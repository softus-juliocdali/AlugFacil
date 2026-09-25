<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

/** Local reconciliation only. A payment confirmation is never proof that a refund failed. */
final class MonthlyRefundService
{
    public function __construct(private PDO $db) {}

    public function reconcile(int $charge, array $payment, string $event): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Refund mensal exige transacao.');
        // Same serialization point as manual affiliate payments; no commission is locked first.
        $q=$this->db->prepare('SELECT afiliado_id_snapshot FROM cobrancas_mensalidades WHERE id=:id');
        $q->execute(['id'=>$charge]);$affiliate=$q->fetchColumn();
        if ($affiliate) {$q=$this->db->prepare('SELECT id FROM afiliados WHERE id=:id FOR UPDATE');$q->execute(['id'=>$affiliate]);}
        $q=$this->db->prepare('SELECT * FROM cobrancas_mensalidades WHERE id=:id FOR UPDATE');$q->execute(['id'=>$charge]);
        $row=$q->fetch();if (!$row) throw new RuntimeException('Cobranca mensal nao encontrada.');
        [$next,$reference]=self::observation($payment,$event,(int)$row['valor_centavos']);
        $state=$row['refund_estado'];$blocked=(bool)$row['refund_bloqueado'];$divergent=false;
        if ($next!==null) {
            $terminal=in_array($state,['concluido','falhou'],true);
            $ambiguous=hash('sha256','ambiguous-monthly-refunds');
            $different=$reference!==null&&$row['refund_referencia']!==null&&!hash_equals($row['refund_referencia'],$reference);
            if ($terminal) {
                // Duplicate/older evidence cannot reopen a terminal refund. Conflicting terminals
                // preserve the proven state but prevent any payout until reviewed.
                if ($different||($state==='falhou'&&$reference!==null&&$row['refund_referencia']===null)||(in_array($next,['concluido','falhou'],true)&&$next!==$state)) {$blocked=true;$divergent=true;}
            } elseif (($different||$row['refund_referencia']===$ambiguous)&&($payment['status']??'')!=='REFUNDED') {
                // A failure from one attempt cannot release another unresolved attempt.
                $state='conciliacao_manual';$blocked=true;$row['refund_referencia']=$ambiguous;
            } else {
                $state=$next;$blocked=in_array($state,['solicitado','processando','conciliacao_manual'],true);
                $row['refund_referencia']=$reference??$row['refund_referencia'];
            }
            $q=$this->db->prepare('UPDATE cobrancas_mensalidades SET refund_estado=:s,refund_referencia=:r,refund_bloqueado=:b,atualizada_em=clock_timestamp() WHERE id=:id');
            $q->execute(['s'=>$state,'r'=>$row['refund_referencia'],'b'=>$blocked?'true':'false','id'=>$charge]);
        }
        return ['state'=>$state,'blocked'=>$blocked,'complete'=>$state==='concluido','divergent'=>$divergent||$state==='conciliacao_manual'||($blocked&&in_array($state,['falhou','concluido'],true))];
    }

    private static function observation(array $payment,string $event,int $total): array
    {
        $items=$payment['refunds']??[];
        // A full REFUNDED payment/event is conclusive for the entire obligation.
        if (($payment['status']??'')==='REFUNDED') return ['concluido',null];
        if (is_array($items)&&count($items)===1&&is_array(reset($items))) {
            $item=reset($items);
            $reference=!empty($item['id'])?hash('sha256','id:'.$item['id']):
                (!empty($item['dateCreated'])?hash('sha256',json_encode([$item['dateCreated'],$item['value']??null,$item['description']??null],JSON_THROW_ON_ERROR)):null);
            $value=0;
            if(($item['status']??'')==='DONE'){
                try{$value=PrecificacaoReservaService::decimalParaCentavos((string)($item['value']??'0'));}
                catch(RuntimeException){return ['conciliacao_manual',$reference];}
            }
            $state=match($item['status']??'') {
                'PENDING'=>'processando','REQUESTED'=>'solicitado',
                'DONE'=>$value===$total?'concluido':'conciliacao_manual',
                'CANCELLED','FAILED','REFUSED'=>'falhou',default=>'conciliacao_manual'
            };
            return [$state,$reference];
        }
        // Multiple/partial/uncorrelated refunds require review, never a fabricated full reversal.
        if (!empty($items)) return ['conciliacao_manual',hash('sha256','ambiguous-monthly-refunds')];
        $status=$payment['status']??'';
        if(in_array($status,['REFUND_REQUESTED','REFUND_IN_PROGRESS'],true))return [$status==='REFUND_REQUESTED'?'solicitado':'processando',null];
        if(str_contains($status,'REFUND')&&!str_contains($event,'REFUND'))return ['conciliacao_manual',null];
        return [match($event) {
            'PAYMENT_REFUND_IN_PROGRESS'=>'processando',
            'PAYMENT_REFUND_REQUESTED'=>'solicitado',
            'PAYMENT_REFUND_DENIED'=>'falhou',
            default=>str_contains($event,'REFUND')?'conciliacao_manual':null
        },null];
    }
}
