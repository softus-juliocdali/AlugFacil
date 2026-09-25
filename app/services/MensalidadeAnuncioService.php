<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Helpers\AsaasHelper;
use PDO;
use RuntimeException;
use Throwable;

final class MensalidadeAnuncioService
{
    public function __construct(private ?PDO $db=null,private ?AsaasPaymentClientInterface $client=null)
    { $this->db??=Database::getConnection(); }

    public function configurar(int $chacaraId,bool $ativa,?int $valorCentavos,int $adminId):array
    {throw new RuntimeException('Configuracao legada encerrada. Use condicoes comerciais e obrigacoes mensais com valor global.');}

    private function sincronizarPrimeiraCobranca(array $mensalidade): void
    {
        $subscription=trim((string)($mensalidade['asaas_subscription_id']??''));
        if($subscription==='')return;
        $resposta=$this->client()->listarCobrancas(['subscription'=>$subscription,'limit'=>10,'offset'=>0]);
        $cobrancas=array_values(array_filter($resposta['data']??[],static function(mixed $item)use($subscription):bool{
            if(!is_array($item)||trim((string)($item['id']??''))==='')return false;
            $vinculo=is_array($item['subscription']??null)?(string)($item['subscription']['id']??''):(string)($item['subscription']??'');
            return $vinculo===''||$vinculo===$subscription;
        }));
        if($cobrancas===[])return;
        usort($cobrancas,static fn(array $a,array $b):int=>[(string)($a['dueDate']??'9999-12-31'),(string)$a['id']]<=>[(string)($b['dueDate']??'9999-12-31'),(string)$b['id']]);
        $payment=$cobrancas[0];
        try{$valor=PrecificacaoReservaService::decimalParaCentavos((string)($payment['value']??$payment['totalValue']??''));}catch(Throwable){$valor=(int)($mensalidade['valor_centavos']??0);}
        if($valor<=0)return;
        $status=match(strtoupper((string)($payment['status']??''))){
            'OVERDUE'=>'ATRASADA',
            'DELETED','DELETED_BY_USER','REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE'=>'CANCELADA',
            default=>'PENDENTE'
        };
        $sql="INSERT INTO cobrancas_mensalidades(mensalidade_id,asaas_payment_id,valor_centavos,status,vencimento,invoice_url) VALUES(:m,:p,:v,:s,:due,:url) ON CONFLICT(asaas_payment_id) DO UPDATE SET valor_centavos=EXCLUDED.valor_centavos,status=CASE WHEN cobrancas_mensalidades.status IN ('PAGA','ATRASADA','CANCELADA','ESTORNADA') THEN cobrancas_mensalidades.status ELSE EXCLUDED.status END,vencimento=COALESCE(EXCLUDED.vencimento,cobrancas_mensalidades.vencimento),invoice_url=COALESCE(EXCLUDED.invoice_url,cobrancas_mensalidades.invoice_url),atualizada_em=CURRENT_TIMESTAMP";
        $this->db->prepare($sql)->execute(['m'=>$mensalidade['id'],'p'=>$payment['id'],'v'=>$valor,'s'=>$status,'due'=>$payment['dueDate']??null,'url'=>$payment['invoiceUrl']??null]);
        $due=trim((string)($payment['dueDate']??''));
        if($due!=='')$this->db->prepare('UPDATE mensalidades_anuncios SET proximo_vencimento=:due,atualizada_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['due'=>$due,'id'=>$mensalidade['id']]);
    }

    public function processarPagamento(array $event,array $payment,string $tipo): array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {$result=$this->processarPagamentoTransacional($event,$payment,$tipo);if($own)$this->db->commit();return $result;}
        catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function processarPagamentoTransacional(array $event,array $payment,string $tipo): array
    {
        $new=(new MonthlyBillingService($this->db,$this->client))->processPayment($event,$payment,$tipo);if($new!==null)return $new;
        $pid=trim((string)($payment['id']??''));$subscription=is_array($payment['subscription']??null)?(string)($payment['subscription']['id']??''):(string)($payment['subscription']??'');
        $external=trim((string)($payment['externalReference']??''));$mensalidade=null;
        if($subscription!==''){$s=$this->db->prepare('SELECT * FROM mensalidades_anuncios WHERE asaas_subscription_id=:id'.$this->lockClause());$s->execute(['id'=>$subscription]);$mensalidade=$s->fetch(PDO::FETCH_ASSOC)?:null;}
        if(!$mensalidade&&preg_match('/^mensalidade_chacara_(\d+)$/',$external,$m))$mensalidade=$this->buscarPorChacara((int)$m[1],true);
        if(!$mensalidade)return ['matched'=>false];
        $recebido=PrecificacaoReservaService::decimalParaCentavos((string)($payment['value']??$payment['totalValue']??''));
        if($recebido<=0)throw new RuntimeException('Valor da mensalidade ausente ou invalido.');
        $statusCobranca=match(true){in_array($tipo,['PAYMENT_CONFIRMED','PAYMENT_RECEIVED'],true)=>'PAGA',$tipo==='PAYMENT_OVERDUE'=>'ATRASADA',str_contains($tipo,'CHARGEBACK')=>'ESTORNADA',$tipo==='PAYMENT_DELETED'=>'CANCELADA',default=>'PENDENTE'};
        $sql="INSERT INTO cobrancas_mensalidades(mensalidade_id,asaas_payment_id,asaas_event_id,valor_centavos,status,vencimento,invoice_url,pago_em) VALUES(:m,:p,:e,:v,:s,:due,:url,:paid) ON CONFLICT(asaas_payment_id) DO UPDATE SET asaas_event_id=COALESCE(cobrancas_mensalidades.asaas_event_id,EXCLUDED.asaas_event_id),status=CASE WHEN cobrancas_mensalidades.status='ESTORNADA' THEN cobrancas_mensalidades.status WHEN cobrancas_mensalidades.status='PAGA' AND EXCLUDED.status IN ('PENDENTE','ATRASADA','CANCELADA') THEN cobrancas_mensalidades.status ELSE EXCLUDED.status END,vencimento=COALESCE(EXCLUDED.vencimento,cobrancas_mensalidades.vencimento),invoice_url=COALESCE(EXCLUDED.invoice_url,cobrancas_mensalidades.invoice_url),pago_em=COALESCE(cobrancas_mensalidades.pago_em,EXCLUDED.pago_em),atualizada_em=CURRENT_TIMESTAMP";
        $this->db->prepare($sql)->execute(['m'=>$mensalidade['id'],'p'=>$pid,'e'=>$event['asaas_event_id'],'v'=>$recebido,'s'=>$statusCobranca,'due'=>$payment['dueDate']??null,'url'=>$payment['invoiceUrl']??null,'paid'=>$statusCobranca==='PAGA'?($payment['paymentDate']??$payment['confirmedDate']??date(DATE_ATOM)):null]);
        $q=$this->db->prepare('SELECT id,valor_centavos FROM cobrancas_mensalidades WHERE asaas_payment_id=:p');$q->execute(['p'=>$pid]);$stored=$q->fetch();
        if((int)$stored['valor_centavos']!==$recebido)throw new RuntimeException('Valor recebido diverge da cobranca mensal.');
        $charge=(int)$stored['id'];$refund=(new MonthlyRefundService($this->db))->reconcile($charge,$payment,$tipo);
        if($refund['complete']){$this->db->prepare("UPDATE cobrancas_mensalidades SET status='ESTORNADA' WHERE id=:id")->execute(['id'=>$charge]);$statusCobranca='ESTORNADA';}
        $novo=$this->calcularStatusFinanceiro((int)$mensalidade['id']);
        $due=trim((string)($payment['dueDate']??''));$next=$due!==''?date('Y-m-d',strtotime($due.($statusCobranca==='PAGA'?' +1 month':''))):null;
        $this->db->prepare('UPDATE mensalidades_anuncios SET proximo_vencimento=COALESCE(:next,proximo_vencimento),atualizada_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['next'=>$next,'id'=>$mensalidade['id']]);
        if($novo!==(string)$mensalidade['status'])$this->alterarStatus($mensalidade,$novo,'webhook',(string)$event['asaas_event_id'],$payment);
        (new AffiliateCommissionService($this->db))->processMonthlyPayment($event,$payment,$refund['complete']?'PAYMENT_REFUNDED':$tipo);
        return ['matched'=>true,'status'=>$refund['divergent']?'divergente':'processado'];
    }

    private function salvarConfiguracao(array $chacara,bool $ativa,?int $valor,string $status,int $adminId,?array $atual,?string $customer,?string $subscription): array
    {
        $sql=<<<'SQL'
          INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status,asaas_customer_id,asaas_subscription_id,configurada_por)
          VALUES(:chacara,:proprietario,:ativa,:valor,:status,:customer,:subscription,:admin)
          ON CONFLICT(chacara_id) DO UPDATE SET ativa=EXCLUDED.ativa,valor_centavos=EXCLUDED.valor_centavos,status=EXCLUDED.status,configurada_por=EXCLUDED.configurada_por,atualizada_em=CURRENT_TIMESTAMP
          RETURNING *
          SQL;
        $ownTransaction = !$this->db->inTransaction();
        if($ownTransaction)$this->db->beginTransaction();
        try {
            $s=$this->db->prepare($sql);$s->execute(['chacara'=>$chacara['id'],'proprietario'=>$chacara['proprietario_id'],'ativa'=>$ativa?'true':'false','valor'=>$valor,'status'=>$status,'customer'=>$customer,'subscription'=>$subscription,'admin'=>$adminId]);$novo=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$atual||$atual['status']!==$status||(int)($atual['valor_centavos']??0)!==(int)$valor||$this->booleano($atual['ativa'])!==$ativa)$this->historico((int)$novo['id'],$atual['status']??null,$status,$valor,'admin',null);
            if($ownTransaction)$this->db->commit();
            return $novo;
        } catch(Throwable $exception) {
            if($ownTransaction&&$this->db->inTransaction())$this->db->rollBack();
            throw $exception;
        }
    }

    private function calcularStatusFinanceiro(int $mensalidadeId): string
    {
        $s=$this->db->prepare(
            "SELECT status,vencimento,pago_em,id FROM cobrancas_mensalidades
             WHERE mensalidade_id=:id ORDER BY id ASC"
        );
        $s->execute(['id'=>$mensalidadeId]);
        $ultimaPaga=null;$ultimaAdversa=null;$temPaga=false;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $cobranca){
            $referencia=(string)($cobranca['vencimento']?:$cobranca['pago_em']?:sprintf('%020d',(int)$cobranca['id']));
            if($cobranca['status']==='PAGA'){$temPaga=true;if($ultimaPaga===null||$referencia>$ultimaPaga)$ultimaPaga=$referencia;}
            if(in_array($cobranca['status'],['ATRASADA','ESTORNADA'],true)&&($ultimaAdversa===null||$referencia>$ultimaAdversa))$ultimaAdversa=$referencia;
        }
        if($ultimaAdversa!==null&&($ultimaPaga===null||$ultimaAdversa>$ultimaPaga))return 'ATRASADA';
        return $temPaga?'EM_DIA':'PENDENTE';
    }
    private function alterarStatus(array $m,string $novo,string $origem,string $ref,array $payment):void
    {
        $this->db->prepare('UPDATE mensalidades_anuncios SET status=CAST(:status AS VARCHAR),ultimo_pagamento_em=CASE WHEN CAST(:status AS VARCHAR)=\'EM_DIA\' THEN CURRENT_TIMESTAMP ELSE ultimo_pagamento_em END,atualizada_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['status'=>$novo,'id'=>$m['id']]);
        $this->historico((int)$m['id'],(string)$m['status'],$novo,(int)$m['valor_centavos'],$origem,$ref);
        $s=$this->db->prepare('SELECT u.id,c.nome FROM mensalidades_anuncios ma INNER JOIN proprietarios p ON p.id=ma.proprietario_id INNER JOIN usuarios u ON u.id=p.usuario_id INNER JOIN chacaras c ON c.id=ma.chacara_id WHERE ma.id=:id');$s->execute(['id'=>$m['id']]);$d=$s->fetch(PDO::FETCH_ASSOC);if(!$d)return;
        if($novo==='ATRASADA'){$titulo='Mensalidade do anuncio pendente';$msg='Seu anuncio '.$d['nome'].' esta temporariamente indisponivel para novos clientes porque a mensalidade esta pendente.';}
        elseif($novo==='EM_DIA'){$titulo='Mensalidade paga';$msg='O anuncio '.$d['nome'].' esta novamente elegivel para clientes.';}else return;
        $this->db->prepare('INSERT INTO notificacoes(usuario_id,tipo,titulo,mensagem,link,chave_deduplicacao) VALUES(:u,:tipo,:titulo,:msg,:link,:chave) ON CONFLICT(chave_deduplicacao) DO NOTHING')->execute(['u'=>$d['id'],'tipo'=>'mensalidade_anuncio','titulo'=>$titulo,'msg'=>$msg,'link'=>'/proprietario/mensalidades','chave'=>'mensalidade:'.$ref.':'.$novo]);
    }
    private function historico(int $id,?string $anterior,string $novo,?int $valor,string $origem,?string $ref):void{$this->db->prepare('INSERT INTO historico_mensalidades_anuncios(mensalidade_id,status_anterior,status_novo,valor_centavos,origem,referencia_externa) VALUES(:id,:a,:n,:v,:o,:r)')->execute(['id'=>$id,'a'=>$anterior,'n'=>$novo,'v'=>$valor,'o'=>$origem,'r'=>$ref]);}
    private function buscarPorChacara(int $id,bool $lock=false):?array{$s=$this->db->prepare('SELECT * FROM mensalidades_anuncios WHERE chacara_id=:id'.($lock?$this->lockClause():''));$s->execute(['id'=>$id]);return$s->fetch(PDO::FETCH_ASSOC)?:null;}
    private function lockClause():string{return$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'?' FOR UPDATE':'';}
    private function client():AsaasPaymentClientInterface{return $this->client??=new AsaasHttpClient();}
    private function booleano(mixed $valor):bool{return $valor===true||$valor===1||$valor==='1'||$valor==='t'||$valor==='true';}
}
