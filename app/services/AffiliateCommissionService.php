<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AffiliateCommissionService
{
    private const CONFIRMATION_EVENTS=['PAYMENT_CONFIRMED','PAYMENT_RECEIVED'];
    private const REVERSAL_EVENTS=['PAYMENT_DELETED','PAYMENT_REFUNDED','PAYMENT_PARTIALLY_REFUNDED','PAYMENT_CHARGEBACK_REQUESTED','PAYMENT_CHARGEBACK_DISPUTE','PAYMENT_AWAITING_CHARGEBACK_REVERSAL'];

    public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}

    public static function calculateCommission(int $baseCents,int $basisPoints): int
    {
        if($baseCents<=0||$basisPoints<0||$basisPoints>10000)throw new RuntimeException('Base ou percentual de comissao invalido.');
        return intdiv(($baseCents*$basisPoints)+5000,10000);
    }

    public function processMonthlyPayment(array $event,array $payment,string $eventType): void
    {
        if(!in_array($eventType,array_merge(self::CONFIRMATION_EVENTS,self::REVERSAL_EVENTS),true))return;
        $paymentId=trim((string)($payment['id']??$event['asaas_payment_id']??''));if($paymentId==='')return;
        $s=$this->db->prepare('SELECT cm.id AS cobranca_id,cm.mensalidade_id,cm.valor_centavos,cm.status AS cobranca_status,cm.refund_estado,cm.refund_bloqueado,cm.pago_em,m.proprietario_id,m.chacara_id,cm.afiliado_id_snapshot AS afiliado_id,cm.percentual_afiliado_bps_snapshot AS percentual_bps FROM cobrancas_mensalidades cm INNER JOIN mensalidades_anuncios m ON m.id=cm.mensalidade_id INNER JOIN proprietarios p ON p.id=m.proprietario_id WHERE cm.asaas_payment_id=:payment LIMIT 1');
        $s->execute(['payment'=>$paymentId]);$origin=$s->fetch(PDO::FETCH_ASSOC);
        if(!$origin||$origin['afiliado_id']===null)return;
        if(in_array($eventType,self::CONFIRMATION_EVENTS,true)){
            if($origin['cobranca_status']!=='PAGA')return;
            $this->createCommission($origin,$event,$paymentId);
            return;
        }
        if(str_contains($eventType,'REFUND')&&$origin['refund_estado']!=='concluido')return;
        if($eventType==='PAYMENT_DELETED'&&$origin['refund_bloqueado'])return;
        $this->reverseCommission($origin,$event,$eventType);
    }

    private function createCommission(array $origin,array $event,string $paymentId): void
    {
        if($origin['percentual_bps']===null)throw new RuntimeException('Snapshot de comissao ausente; reconciliacao obrigatoria.');
        $base=(int)$origin['valor_centavos'];$bps=(int)$origin['percentual_bps'];$commission=self::calculateCommission($base,$bps);
        $confirmed=$this->dateTime((string)($origin['pago_em']??''));$available=$confirmed->modify('+7 days');
        $sql='INSERT INTO comissoes_afiliados(afiliado_id,proprietario_id,chacara_id,mensalidade_id,cobranca_mensalidade_id,asaas_payment_id,valor_base_centavos,percentual_bps,valor_comissao_centavos,confirmado_em,disponivel_em,status,evento_confirmacao_id) VALUES(:afiliado,:proprietario,:chacara,:mensalidade,:cobranca,:payment,:base,:bps,:comissao,:confirmado,:disponivel,\'EM_ABERTO\',:evento) ON CONFLICT(cobranca_mensalidade_id) DO NOTHING';
        $this->db->prepare($sql)->execute(['afiliado'=>$origin['afiliado_id'],'proprietario'=>$origin['proprietario_id'],'chacara'=>$origin['chacara_id'],'mensalidade'=>$origin['mensalidade_id'],'cobranca'=>$origin['cobranca_id'],'payment'=>$paymentId,'base'=>$base,'bps'=>$bps,'comissao'=>$commission,'confirmado'=>$confirmed->format(DATE_ATOM),'disponivel'=>$available->format(DATE_ATOM),'evento'=>$event['asaas_event_id']??null]);
    }

    private function reverseCommission(array $origin,array $event,string $eventType): void
    {
        $s=$this->db->prepare('SELECT * FROM comissoes_afiliados WHERE cobranca_mensalidade_id=:cobranca FOR UPDATE');$s->execute(['cobranca'=>$origin['cobranca_id']]);$commission=$s->fetch(PDO::FETCH_ASSOC);if(!$commission)return;
        if($commission['estornada_em']!==null)return;
        $allocatedStatement=$this->db->prepare('SELECT COALESCE(SUM(valor_centavos),0) FROM alocacoes_pagamentos_afiliados WHERE comissao_afiliado_id=:id');$allocatedStatement->execute(['id'=>$commission['id']]);$allocated=(int)$allocatedStatement->fetchColumn();
        $eventId=trim((string)($event['asaas_event_id']??''));$reason='Reversao da mensalidade por '.$eventType;
        if($allocated>0){
            $this->db->prepare("INSERT INTO ajustes_afiliados(afiliado_id,comissao_afiliado_id,tipo,valor_centavos,asaas_event_id,motivo) VALUES(:afiliado,:comissao,'ESTORNO_COMISSAO_PAGA',:valor,:evento,:motivo) ON CONFLICT(comissao_afiliado_id,tipo) DO NOTHING")->execute(['afiliado'=>$commission['afiliado_id'],'comissao'=>$commission['id'],'valor'=>-$allocated,'evento'=>$eventId?:null,'motivo'=>$reason]);
        }
        $status=$allocated>=(int)$commission['valor_comissao_centavos']?'PAGA':'ESTORNADA';
        $this->db->prepare('UPDATE comissoes_afiliados SET status=:status,estornada_em=CURRENT_TIMESTAMP,evento_estorno_id=:evento,motivo_estorno=:motivo,atualizada_em=CURRENT_TIMESTAMP WHERE id=:id AND estornada_em IS NULL')->execute(['status'=>$status,'evento'=>$eventId?:null,'motivo'=>$reason,'id'=>$commission['id']]);
    }

    public function summary(int $affiliateId,?DateTimeImmutable $asOf=null): array
    {
        $asOf??=new DateTimeImmutable('now');
        $s=$this->db->prepare("SELECT c.*,EXISTS(SELECT 1 FROM cobrancas_mensalidades cm WHERE cm.id=c.cobranca_mensalidade_id AND cm.refund_bloqueado) AS refund_bloqueado,COALESCE((SELECT SUM(a.valor_centavos) FROM alocacoes_pagamentos_afiliados a WHERE a.comissao_afiliado_id=c.id),0) AS alocado FROM comissoes_afiliados c WHERE c.afiliado_id=:afiliado");$s->execute(['afiliado'=>$affiliateId]);
        $open=0;$grossAvailable=0;$blocked=0;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as$row){
            if(in_array($row['status'],['PAGA','ESTORNADA','CANCELADA'],true)||$row['estornada_em']!==null)continue;
            $remaining=max(0,(int)$row['valor_comissao_centavos']-(int)$row['alocado']);if($remaining===0)continue;
            if($row['refund_bloqueado']){$blocked+=$remaining;continue;}
            if($this->dateTime((string)$row['disponivel_em'])<=$asOf)$grossAvailable+=$remaining;else$open+=$remaining;
        }
        $q=$this->db->prepare('SELECT COALESCE(SUM(valor_centavos),0) FROM ajustes_afiliados WHERE afiliado_id=:id');$q->execute(['id'=>$affiliateId]);$adjustments=(int)$q->fetchColumn();
        $q=$this->db->prepare('SELECT COALESCE(SUM(valor_centavos),0) FROM pagamentos_afiliados WHERE afiliado_id=:id');$q->execute(['id'=>$affiliateId]);$paid=(int)$q->fetchColumn();
        return ['bloqueado_centavos'=>$blocked,'em_aberto_centavos'=>$open,'disponivel_bruto_centavos'=>$grossAvailable,'ajustes_centavos'=>$adjustments,'saldo_disponivel_centavos'=>$grossAvailable+$adjustments,'total_pago_centavos'=>$paid];
    }

    public function registerManualPayment(int $affiliateId,int $valueCents,string $paymentDate,?string $reference,?string $observation,int $administratorId,?DateTimeImmutable $asOf=null): array
    {
        if($valueCents<=0)throw new RuntimeException('Informe um valor de pagamento maior que zero.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$paymentDate);if(!$date||$date->format('Y-m-d')!==$paymentDate)throw new RuntimeException('Informe uma data de pagamento valida.');
        $asOf??=new DateTimeImmutable('now');$own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $lock=$this->db->prepare('SELECT id FROM afiliados WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$affiliateId]);if(!$lock->fetchColumn())throw new RuntimeException('Afiliado nao encontrado.');
            $commissions=$this->availableCommissionsForUpdate($affiliateId,$asOf);
            $gross=array_sum(array_column($commissions,'restante_centavos'));
            $q=$this->db->prepare('SELECT COALESCE(SUM(valor_centavos),0) FROM ajustes_afiliados WHERE afiliado_id=:id');$q->execute(['id'=>$affiliateId]);$adjustments=(int)$q->fetchColumn();$available=$gross+$adjustments;
            if($valueCents>$available)throw new RuntimeException('O valor informado é superior ao saldo disponível do afiliado.');
            $p=$this->db->prepare('INSERT INTO pagamentos_afiliados(afiliado_id,valor_centavos,data_pagamento,referencia,observacao,administrador_id) VALUES(:afiliado,:valor,:data,:referencia,:observacao,:admin) RETURNING id');$p->execute(['afiliado'=>$affiliateId,'valor'=>$valueCents,'data'=>$paymentDate,'referencia'=>$this->limited($reference,180),'observacao'=>$this->limited($observation,1000),'admin'=>$administratorId]);$paymentId=(int)$p->fetchColumn();
            $remaining=$valueCents;
            foreach($commissions as$commission){if($remaining<=0)break;$allocation=min($remaining,(int)$commission['restante_centavos']);if($allocation<=0)continue;
                $this->db->prepare('INSERT INTO alocacoes_pagamentos_afiliados(pagamento_afiliado_id,comissao_afiliado_id,valor_centavos) VALUES(:pagamento,:comissao,:valor)')->execute(['pagamento'=>$paymentId,'comissao'=>$commission['id'],'valor'=>$allocation]);
                $newAllocated=(int)$commission['alocado_centavos']+$allocation;$newStatus=$newAllocated>=(int)$commission['valor_comissao_centavos']?'PAGA':'DISPONIVEL';
                $this->db->prepare('UPDATE comissoes_afiliados SET status=:status,atualizada_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['status'=>$newStatus,'id'=>$commission['id']]);$remaining-=$allocation;
            }
            if($remaining!==0)throw new RuntimeException('Nao foi possivel alocar integralmente o pagamento.');
            if($own)$this->db->commit();return ['id'=>$paymentId,'valor_centavos'=>$valueCents];
        }catch(Throwable$exception){if($own&&$this->db->inTransaction())$this->db->rollBack();throw$exception;}
    }

    private function availableCommissionsForUpdate(int $affiliateId,DateTimeImmutable $asOf): array
    {
        $sql="SELECT c.*,COALESCE((SELECT SUM(a.valor_centavos) FROM alocacoes_pagamentos_afiliados a WHERE a.comissao_afiliado_id=c.id),0) AS alocado_centavos FROM comissoes_afiliados c WHERE c.afiliado_id=:afiliado AND c.status IN ('EM_ABERTO','DISPONIVEL') AND c.estornada_em IS NULL AND NOT EXISTS(SELECT 1 FROM cobrancas_mensalidades cm WHERE cm.id=c.cobranca_mensalidade_id AND cm.refund_bloqueado) AND c.disponivel_em<=:agora ORDER BY c.disponivel_em,c.id FOR UPDATE OF c";
        $s=$this->db->prepare($sql);$s->execute(['afiliado'=>$affiliateId,'agora'=>$asOf->format(DATE_ATOM)]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row)$row['restante_centavos']=max(0,(int)$row['valor_comissao_centavos']-(int)$row['alocado_centavos']);unset($row);
        return array_values(array_filter($rows,static fn(array$row):bool=>(int)$row['restante_centavos']>0));
    }

    public function commissions(int $affiliateId): array
    {
        $s=$this->db->prepare("SELECT c.*,EXISTS(SELECT 1 FROM cobrancas_mensalidades cm WHERE cm.id=c.cobranca_mensalidade_id AND cm.refund_bloqueado) AS refund_bloqueado,ch.nome AS chacara_nome,COALESCE((SELECT SUM(a.valor_centavos) FROM alocacoes_pagamentos_afiliados a WHERE a.comissao_afiliado_id=c.id),0) AS alocado_centavos FROM comissoes_afiliados c INNER JOIN chacaras ch ON ch.id=c.chacara_id WHERE c.afiliado_id=:id ORDER BY c.confirmado_em DESC,c.id DESC LIMIT 300");$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function payments(int $affiliateId): array{$s=$this->db->prepare('SELECT p.*,u.nome AS administrador_nome FROM pagamentos_afiliados p LEFT JOIN usuarios u ON u.id=p.administrador_id WHERE p.afiliado_id=:id ORDER BY p.data_pagamento DESC,p.id DESC LIMIT 200');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function adjustments(int $affiliateId): array{$s=$this->db->prepare('SELECT a.*,c.asaas_payment_id,ch.nome AS chacara_nome FROM ajustes_afiliados a INNER JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id INNER JOIN chacaras ch ON ch.id=c.chacara_id WHERE a.afiliado_id=:id ORDER BY a.criado_em DESC,a.id DESC LIMIT 200');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function allocations(int $affiliateId): array{$s=$this->db->prepare('SELECT a.*,p.data_pagamento,p.referencia,c.asaas_payment_id,ch.nome AS chacara_nome FROM alocacoes_pagamentos_afiliados a INNER JOIN pagamentos_afiliados p ON p.id=a.pagamento_afiliado_id INNER JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id INNER JOIN chacaras ch ON ch.id=c.chacara_id WHERE p.afiliado_id=:id ORDER BY p.data_pagamento DESC,p.id DESC,a.id ASC LIMIT 400');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}

    private function dateTime(string$value):DateTimeImmutable{try{return$value!==''?new DateTimeImmutable($value):new DateTimeImmutable('now');}catch(Throwable){return new DateTimeImmutable('now');}}
    private function limited(?string$value,int$limit):?string{$value=trim((string)$value);return$value===''?null:mb_substr($value,0,$limit);}
}
