<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/** Individual monthly obligations; card capture is a separate durable operation. */
final class MonthlyBillingService
{
    public function __construct(private ?PDO $db=null,private ?AsaasPaymentClientInterface $client=null){$this->db??=Database::getConnection();}
    public function issue(int $id,int $owner,string $method):array
    {
        if(!in_array($method,['PIX','CREDIT_CARD'],true))throw new RuntimeException('Mensalidade aceita PIX ou cartao.');
        $this->db->beginTransaction();try {
            $q=$this->db->prepare('SELECT o.*,p.usuario_id FROM obrigacoes_mensalidades o JOIN proprietarios p ON p.id=o.proprietario_id WHERE o.id=:id AND o.proprietario_id=:p FOR UPDATE OF o');$q->execute(['id'=>$id,'p'=>$owner]);$o=$q->fetch();
            if(!$o||$o['estado']!=='pendente')throw new RuntimeException('Obrigacao indisponivel para este proprietario.');
            if($o['forma_pagamento']!==null&&$o['forma_pagamento']!==$method)throw new RuntimeException('Ja existe instrumento escolhido. Concilie ou cancele antes de trocar o meio.');
            if($o['asaas_payment_id']){$this->db->commit();return $o;}
            if($this->client===null || $this->client instanceof AsaasHttpClient) FinancialReleasePolicy::assertMonthlyPaymentsAllowed();
            $this->db->prepare('UPDATE obrigacoes_mensalidades SET forma_pagamento=:m WHERE id=:id')->execute(['m'=>$method,'id'=>$id]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $client=$this->client??=new AsaasHttpClient();
        if(!method_exists($client,'accountScope'))throw new RuntimeException('Conta gateway nao identificada.');$scope=$client->accountScope();
        $customer=(new AsaasCustomerService($client,$this->db,$scope))->obter((int)$o['usuario_id']);
        $payload=['customer'=>$customer['id'],'billingType'=>$method,'value'=>PrecificacaoReservaService::centavosParaDecimal((int)$o['valor_centavos']),'dueDate'=>$o['vencimento'],'description'=>'Mensalidade de anuncio AlugFacil #'.$o['chacara_id']];
        $result=(new FinancialOperationService($scope,$this->db))->executar('cobranca','mensalidade:'.$id,1,$payload,
            function(string $ref,array $p)use($client):array{
                $matches=[];$offset=0;do{$r=$client->listarCobrancas(['externalReference'=>$ref,'limit'=>100,'offset'=>$offset]);foreach($r['data']??[] as $payment){if(($payment['externalReference']??'')!==$ref)continue;if(($payment['customer']??'')!==$p['customer']||($payment['billingType']??'')!==$p['billingType']||($payment['dueDate']??'')!==$p['dueDate']||PrecificacaoReservaService::decimalParaCentavos((string)($payment['value']??''))!==PrecificacaoReservaService::decimalParaCentavos((string)$p['value']))throw new RuntimeException('Cobranca remota diverge do snapshot.');$matches[]=$payment;}$offset+=100;}while(!empty($r['hasMore']));return $matches;
            },fn(string $ref,array $p):array=>$client->criarCobranca($p+['externalReference'=>$ref]));
        $this->db->beginTransaction();try {
            $this->db->prepare('UPDATE obrigacoes_mensalidades SET asaas_payment_id=:p,invoice_url=:url,operacao_id=:op,atualizada_em=clock_timestamp() WHERE id=:id')->execute(['p'=>$result['id'],'url'=>$result['invoiceUrl']??null,'op'=>$result['_operation_id'],'id'=>$id]);
            $q=$this->db->prepare("INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status,asaas_customer_id) VALUES(:c,:p,TRUE,:v,'PENDENTE',:customer) ON CONFLICT(chacara_id) DO UPDATE SET ativa=TRUE,valor_centavos=EXCLUDED.valor_centavos,asaas_customer_id=EXCLUDED.asaas_customer_id RETURNING id");$q->execute(['c'=>$o['chacara_id'],'p'=>$owner,'v'=>$o['valor_centavos'],'customer'=>$customer['id']]);$monthly=(int)$q->fetchColumn();
            $this->db->prepare("INSERT INTO cobrancas_mensalidades(mensalidade_id,obrigacao_id,asaas_payment_id,valor_centavos,status,vencimento,invoice_url) VALUES(:m,:o,:p,:v,'PENDENTE',:d,:url) ON CONFLICT(asaas_payment_id) DO NOTHING")->execute(['m'=>$monthly,'o'=>$id,'p'=>$result['id'],'v'=>$o['valor_centavos'],'d'=>$o['vencimento'],'url'=>$result['invoiceUrl']??null]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return array_merge($o,['asaas_payment_id'=>$result['id'],'invoice_url'=>$result['invoiceUrl']??null]);
    }
    public function processPayment(array $event,array $payment,string $type):?array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT o.*,cm.id cobranca_id,cm.mensalidade_id FROM obrigacoes_mensalidades o JOIN cobrancas_mensalidades cm ON cm.obrigacao_id=o.id WHERE o.asaas_payment_id=:p FOR UPDATE OF o,cm');$q->execute(['p'=>$payment['id']??'']);$o=$q->fetch();if(!$o){if($own)$this->db->commit();return null;}
            if(PrecificacaoReservaService::decimalParaCentavos((string)($payment['value']??''))!==(int)$o['valor_centavos'])throw new RuntimeException('Valor recebido diverge da obrigacao mensal.');
            if($o['forma_pagamento']!==null&&($payment['billingType']??'')!==$o['forma_pagamento'])throw new RuntimeException('Meio recebido diverge da obrigacao mensal.');
            if($o['operacao_id']){$s=$this->db->prepare('SELECT payload,referencia FROM operacoes_financeiras WHERE id=:id');$s->execute(['id'=>$o['operacao_id']]);$operation=$s->fetch();$expected=json_decode($operation['payload'],true,512,JSON_THROW_ON_ERROR);if(($payment['customer']??'')!==$expected['customer']||($payment['externalReference']??'')!==$operation['referencia'])throw new RuntimeException('Customer ou correlacao da mensalidade divergente.');}
            $refund=(new MonthlyRefundService($this->db))->reconcile((int)$o['cobranca_id'],$payment,$type);
            $state=match(true){in_array($type,['PAYMENT_CONFIRMED','PAYMENT_RECEIVED'],true)=>'paga',$refund['complete']||str_contains($type,'CHARGEBACK')=>'estornada',$type==='PAYMENT_DELETED'=>'cancelada',default=>$o['estado']};
            if($refund['complete'])$state='estornada';
            if($o['estado']==='estornada'||($o['estado']==='paga'&&in_array($state,['pendente','cancelada'],true)))$state=$o['estado'];
            $this->db->prepare('UPDATE obrigacoes_mensalidades SET estado=:s,atualizada_em=clock_timestamp() WHERE id=:id')->execute(['s'=>$state,'id'=>$o['id']]);
            $billing=match($state){'paga'=>'PAGA','estornada'=>'ESTORNADA','cancelada'=>'CANCELADA',default=>$type==='PAYMENT_OVERDUE'?'ATRASADA':'PENDENTE'};
            $this->db->prepare('UPDATE cobrancas_mensalidades SET status=:s,pago_em=COALESCE(pago_em,CAST(:paid AS timestamptz)),asaas_event_id=:e,atualizada_em=clock_timestamp() WHERE id=:id')->execute(['s'=>$billing,'paid'=>$state==='paga'?($payment['paymentDate']??$payment['confirmedDate']??date(DATE_ATOM)):null,'e'=>$event['asaas_event_id']??null,'id'=>$o['cobranca_id']]);
            $status=$state==='paga'?'EM_DIA':($billing==='ATRASADA'?'ATRASADA':'PENDENTE');
            $this->db->prepare('UPDATE mensalidades_anuncios SET status=:s,atualizada_em=clock_timestamp() WHERE id=:id')->execute(['s'=>$status,'id'=>$o['mensalidade_id']]);
            (new AffiliateCommissionService($this->db))->processMonthlyPayment($event,$payment,$refund['complete']?'PAYMENT_REFUNDED':$type);
            if($own)$this->db->commit();return ['matched'=>true,'status'=>$refund['divergent']?'divergente':'processado'];
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
