<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class AsaasWebhookService
{
    private const CONFIRMACAO=['PAYMENT_CONFIRMED','PAYMENT_RECEIVED'];
    private const INFORMATIVOS=['PAYMENT_CREATED','PAYMENT_UPDATED','PAYMENT_PENDING','PAYMENT_AWAITING_RISK_ANALYSIS','PAYMENT_APPROVED_BY_RISK_ANALYSIS','PAYMENT_REPROVED_BY_RISK_ANALYSIS','PAYMENT_OVERDUE'];
    private const REVERSAO=['PAYMENT_DELETED','PAYMENT_REFUNDED','PAYMENT_PARTIALLY_REFUNDED','PAYMENT_REFUND_IN_PROGRESS','PAYMENT_CHARGEBACK_REQUESTED','PAYMENT_CHARGEBACK_DISPUTE','PAYMENT_AWAITING_CHARGEBACK_REVERSAL'];
    public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}

    public function processarPorId(int $id, bool $retryErrors=false, bool $dryRun=false): string
    {
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT * FROM asaas_webhook_eventos WHERE id=:id FOR UPDATE SKIP LOCKED');$s->execute(['id'=>$id]);$event=$s->fetch();
            if(!$event){$this->db->rollBack();return 'ocupado';}
            $allowed=['recebido'];if($retryErrors)$allowed[]='erro';
            if(!in_array($event['status_processamento'],$allowed,true) || ($event['proxima_tentativa_em'] && strtotime($event['proxima_tentativa_em'])>time())){$this->db->rollBack();return 'ignorado';}
            if($dryRun){$this->db->rollBack();return 'dry-run';}
            $this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento='processando',quantidade_tentativas=quantidade_tentativas+1,iniciado_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id'=>$id]);
            $payload=is_string($event['payload'])?json_decode($event['payload'],true):$event['payload'];
            if(!is_array($payload))throw new RuntimeException('Payload persistido invalido.');
            $outcome=$this->aplicar($event,$payload);
            $this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento=:status,ultimo_erro=:erro,processado_em=CURRENT_TIMESTAMP,proxima_tentativa_em=NULL,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id'=>$id,'status'=>$outcome['status'],'erro'=>$outcome['erro']]);
            $this->db->commit();app_log('Webhook Asaas processado. event_id='.(string)$event['asaas_event_id'].' status='.$outcome['status']);return $outcome['status'];
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();$this->registrarErro($id,$e);return 'erro';
        }
    }

    private function aplicar(array $event,array $payload): array
    {
        $tipo=(string)$event['tipo_evento'];
        if(!in_array($tipo,array_merge(self::CONFIRMACAO,self::INFORMATIVOS,self::REVERSAO),true))return ['status'=>'ignorado','erro'=>null];
        $payment=is_array($payload['payment']??null)?$payload['payment']:[];$pid=trim((string)($payment['id']??''));
        if($pid===''||$pid!==(string)$event['asaas_payment_id'])return $this->divergencia('Identificador da cobranca ausente ou divergente.');
        $s=$this->db->prepare('SELECT r.*,p.id AS pagamento_id,p.status_pagamento AS pagamento_registrado,p.valor AS pagamento_valor FROM reservas r LEFT JOIN pagamentos p ON p.reserva_id=r.id AND p.id_transacao_asaas=:payment WHERE r.id_cobranca_asaas=:payment LIMIT 1 FOR UPDATE OF r');$s->execute(['payment'=>$pid]);$reserva=$s->fetch();
        if(!$reserva)return $this->divergencia('Cobranca sem reserva interna correspondente.');
        $external=trim((string)($payment['externalReference']??''));
        if($external!=='reserva_'.(int)$reserva['id'])return $this->divergencia('External reference divergente da reserva vinculada.');
        try{$recebido=PrecificacaoReservaService::decimalParaCentavos((string)($payment['value']??$payment['totalValue']??''));}catch(Throwable){return $this->divergencia('Valor recebido ausente ou invalido.');}
        $esperado=$reserva['valor_total_cliente_centavos']!==null?(int)$reserva['valor_total_cliente_centavos']:PrecificacaoReservaService::decimalParaCentavos((string)$reserva['valor_total']);
        if($recebido!==$esperado)return $this->divergencia('Valor recebido diverge do valor esperado.');
        $forma=strtoupper((string)($payment['billingType']??''));if(($reserva['forma_pagamento']??null)!==null&&$forma!==(string)$reserva['forma_pagamento'])return $this->divergencia('Forma de pagamento diverge do snapshot.');
        if(in_array($tipo,self::REVERSAO,true)){$this->marcarDivergencia((int)$reserva['id']);return $this->divergencia('Evento financeiro reverso requer conciliacao manual.');}
        if(in_array($tipo,self::CONFIRMACAO,true))return $this->confirmar($reserva,$payment,$tipo);
        $this->salvarPagamento($reserva,$payment,'pendente');
        return ['status'=>'processado','erro'=>null];
    }

    private function confirmar(array $reserva,array $payment,string $tipo): array
    {
        $this->salvarPagamento($reserva,$payment,'pago');
        $this->db->prepare("UPDATE reservas SET status_pagamento='pago',data_atualizacao=CURRENT_TIMESTAMP WHERE id=:id AND status_pagamento<>'estornado'")->execute(['id'=>$reserva['id']]);
        if(in_array($reserva['status_reserva'],ReservaStatusService::FINAIS,true)){$this->marcarDivergencia((int)$reserva['id']);return $this->divergencia('Pagamento tardio em reserva com estado final; reserva nao reativada.');}
        if($reserva['status_reserva']==='confirmada')return ['status'=>'processado','erro'=>null];
        if(!ReservaStatusService::podeTransicionar((string)$reserva['status_reserva'],'confirmada')){$this->marcarDivergencia((int)$reserva['id']);return $this->divergencia('Estado atual da reserva nao permite confirmacao.');}
        (new ReservaStatusService($this->db))->transicionar((int)$reserva['id'],'confirmada',['motivo'=>'Pagamento Asaas confirmado','origem'=>'webhook','responsavel_tipo'=>'webhook','metadados'=>['event'=>$tipo]]);
        $this->db->prepare("UPDATE repasses_reservas SET pagamento_id=(SELECT id FROM pagamentos WHERE reserva_id=:id AND status_pagamento='pago' ORDER BY id DESC LIMIT 1),status_local=CASE WHEN repasse_liberavel_em<=CURRENT_TIMESTAMP THEN 'liberado_para_repasse' ELSE 'aguardando_liberacao' END,atualizado_em=CURRENT_TIMESTAMP WHERE reserva_id=:id AND status_local='aguardando_pagamento'")->execute(['id'=>$reserva['id']]);
        return ['status'=>'processado','erro'=>null];
    }

    private function salvarPagamento(array $r,array $p,string $status):void
    {
        $sql="INSERT INTO pagamentos (reserva_id,usuario_id,valor,forma_pagamento,status_pagamento,id_transacao_asaas,data_pagamento) VALUES (:reserva,:usuario,:valor,:forma,:status,:payment,:data) ON CONFLICT (id_transacao_asaas) DO UPDATE SET status_pagamento=CASE WHEN pagamentos.status_pagamento IN ('pago','estornado') THEN pagamentos.status_pagamento ELSE EXCLUDED.status_pagamento END,data_pagamento=COALESCE(pagamentos.data_pagamento,EXCLUDED.data_pagamento)";
        $centavos=$r['valor_total_cliente_centavos']!==null?(int)$r['valor_total_cliente_centavos']:PrecificacaoReservaService::decimalParaCentavos((string)$r['valor_total']);$this->db->prepare($sql)->execute(['reserva'=>$r['id'],'usuario'=>$r['usuario_id'],'valor'=>PrecificacaoReservaService::centavosParaDecimal($centavos),'forma'=>$this->forma((string)($p['billingType']??'')),'status'=>$status,'payment'=>$p['id'],'data'=>$status==='pago'?($p['paymentDate']??$p['confirmedDate']??date('Y-m-d H:i:s')):null]);
    }
    private function forma(string $v):string{return match(strtoupper($v)){'PIX'=>'pix','CREDIT_CARD'=>'cartao_credito','BOLETO'=>'boleto',default=>'transferencia'};}
    private function marcarDivergencia(int $id):void{$this->db->prepare('UPDATE reservas SET divergencia_pagamento=TRUE,data_atualizacao=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$id]);}
    private function divergencia(string $erro):array{return ['status'=>'divergente','erro'=>$this->sanitizar($erro)];}
    private function registrarErro(int $id,Throwable $e):void
    {
        $s=$this->db->prepare('SELECT quantidade_tentativas FROM asaas_webhook_eventos WHERE id=:id');$s->execute(['id'=>$id]);$n=max(1,(int)$s->fetchColumn());$delay=[60,300,900,3600][min($n-1,3)];
        $message=$e instanceof \PDOException?'Falha interna de persistencia.':$this->sanitizar($e->getMessage());
        $u=$this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento='erro',quantidade_tentativas=quantidade_tentativas+1,ultimo_erro=:erro,proxima_tentativa_em=CURRENT_TIMESTAMP+(:delay||' seconds')::interval,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id");$u->execute(['id'=>$id,'erro'=>$message,'delay'=>$delay]);app_log('Erro ao processar webhook Asaas. evento_interno=' . $id);
    }
    private function sanitizar(string $v):string{$v=preg_replace('/[\r\n\t]+/',' ',$v)??'Erro de processamento.';foreach([getenv('DB_PASS'),getenv('ASAAS_API_KEY'),getenv('ASAAS_WEBHOOK_TOKEN')]as$secret){if(is_string($secret)&&$secret!=='')$v=str_replace($secret,'[REDACTED]',$v);}return mb_substr($v,0,1000);}
}
