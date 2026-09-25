<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ReservationBillingService
{
 public function __construct(private ?PDO $db=null,private ?AsaasPaymentClientInterface $client=null){$this->db??=Database::getConnection();}
 public function issue(int $reservation,int $user,bool $recurringConsent=false,?string $payerIp=null):array
 {
  $this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,$reservation);(new ReservationAuthorization($this->db))->assertReservation($user,$reservation,'reserva.pagar_proprias');
   if($r['schema_financeiro']!==2||$r['status_reserva']!=='aguardando_pagamento'||new DateTimeImmutable($r['expira_em'])<=new DateTimeImmutable($r['_agora']))throw new RuntimeException('Checkout encerrado.');
   if($r['modalidade']==='entrada_parcelamento'&&$r['forma_pagamento']==='CREDIT_CARD')
    (new ReservationCardVault($this->db))->consent($r,$user,$recurringConsent,$payerIp);
   if($r['modalidade']==='entrada_parcelamento'&&$r['forma_pagamento']==='BOLETO'&&$r['entrada_boleto_emitida_em']===null){
    $this->db->prepare("UPDATE reservas SET entrada_boleto_emitida_em=CAST(:now AS timestamptz),expira_em=CAST(:deadline AS timestamptz)+INTERVAL '1 day' WHERE id=:r")->execute(['now'=>$r['_agora'],'deadline'=>$r['_agora'],'r'=>$reservation]);
    $r=ReservationLock::acquire($this->db,$reservation);
   }
   if(!AsaasSubcontaService::isFinanciallyEnabled($this->db,(int)$r['proprietario_id']))throw new RuntimeException('Recebimentos do proprietario ainda nao habilitados.');
   $s=json_decode($r['precificacao_detalhes'],true,512,JSON_THROW_ON_ERROR);$p=$s['pagamentos'][0];
   $q=$this->db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,forma_pagamento) VALUES(:r,0,:tipo,:h,:o,:c,:p,:t,:d,:m) ON CONFLICT(reserva_id,numero) DO NOTHING");
   $q->execute(['r'=>$reservation,'tipo'=>$p['tipo'],'h'=>$p['hospedagem_centavos'],'o'=>$p['taxa_operacional_centavos'],'c'=>$p['comissao_centavos'],'p'=>$p['proprietario_centavos'],'t'=>$p['total_centavos'],'d'=>$r['expira_em'],'m'=>$r['forma_pagamento']]);
   $q=$this->db->prepare('SELECT * FROM obrigacoes_reserva WHERE reserva_id=:r AND numero=0 FOR UPDATE');$q->execute(['r'=>$reservation]);$o=$q->fetch();$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  if($o['asaas_payment_id'])return $o;
  $client=$this->client??=new AsaasHttpClient();$scope=$client->accountScope();$customer=(new AsaasCustomerService($client,$this->db,$scope))->obter($user);
  $payload=['customer'=>$customer['id'],'billingType'=>$o['forma_pagamento'],'value'=>PrecificacaoReservaService::centavosParaDecimal($o['total_centavos']),
   'dueDate'=>(new DateTimeImmutable($o['vencimento_em']))->format('Y-m-d'),'description'=>'Reserva AlugFacil #'.$reservation,'interest'=>['value'=>0],'fine'=>['value'=>0]];
  $result=(new FinancialOperationService($scope,$this->db))->executar('cobranca','reserva-obrigacao:'.$o['id'],1,$payload,
   function(string $ref,array $p)use($client):array{$found=[];$offset=0;do{$page=$client->listarCobrancas(['externalReference'=>$ref,'limit'=>100,'offset'=>$offset]);foreach($page['data']??[] as $x){if(($x['externalReference']??null)!==$ref)continue;$this->assertPayment($x,$p,$ref);$found[]=$x;}$offset+=100;}while(!empty($page['hasMore']));return $found;},
   fn(string $ref,array $p):array=>$client->criarCobranca($p+['externalReference'=>$ref]),
   function(string $ref,array $p)use($reservation):void{
    $this->db->beginTransaction();try{$r=ReservationLock::acquire($this->db,$reservation);if($r['status_reserva']!=='aguardando_pagamento'||new DateTimeImmutable($r['expira_em'])<=new DateTimeImmutable($r['_agora']))throw new RuntimeException('Checkout encerrado antes do envio.');$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   });
  $this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,$reservation);
   $this->db->prepare('UPDATE obrigacoes_reserva SET asaas_payment_id=:p,invoice_url=:url,operacao_id=:op,asaas_customer_id=:customer,atualizado_em=clock_timestamp() WHERE id=:id')->execute(['p'=>$result['id'],'url'=>$result['invoiceUrl']??null,'op'=>$result['_operation_id'],'customer'=>$customer['id'],'id'=>$o['id']]);
   $this->db->prepare('UPDATE reservas SET id_cobranca_asaas=:p,link_pagamento_asaas=:url WHERE id=:r')->execute(['p'=>$result['id'],'url'=>$result['invoiceUrl']??null,'r'=>$reservation]);
   if(in_array($r['status_reserva'],ReservaStatusService::FINAIS,true)||($r['status_reserva']==='aguardando_pagamento'&&new DateTimeImmutable($r['expira_em'])<=new DateTimeImmutable($r['_agora'])))$this->cancelJob($reservation,(int)$o['id']);
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  return array_merge($o,['asaas_payment_id'=>$result['id'],'invoice_url'=>$result['invoiceUrl']??null]);
 }
 public function issueScheduled(int $obligation):array
 {
  $q=$this->db->prepare('SELECT reserva_id FROM obrigacoes_reserva WHERE id=:id');$q->execute(['id'=>$obligation]);$id=$q->fetchColumn();if($id===false)throw new RuntimeException('Parcela nao encontrada.');
  $this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,(int)$id);$q=$this->db->prepare('SELECT * FROM obrigacoes_reserva WHERE id=:id FOR UPDATE');$q->execute(['id'=>$obligation]);$o=$q->fetch();
   if($o['asaas_payment_id']){$this->db->commit();return $o;}
   if($r['status_reserva']!=='confirmada'||$r['entrada_confirmada_em']===null||$o['numero']===0||$o['estado']!=='pendente'||new DateTimeImmutable($o['cancelamento_em'])<=new DateTimeImmutable($r['_agora']))throw new RuntimeException('Parcela fora da janela de pagamento.');
   $cutoff=new DateTimeImmutable(json_decode($r['precificacao_detalhes'],true,512,JSON_THROW_ON_ERROR)['limite_ultima_parcela']);
   if($cutoff<=new DateTimeImmutable($r['_agora']))throw new RuntimeException('Parcela fora da janela de pagamento.');
   if(!in_array($o['forma_pagamento'],['PIX','BOLETO','CREDIT_CARD'],true))throw new RuntimeException('Meio recorrente indisponivel.');
   if($o['forma_pagamento']==='CREDIT_CARD'&&new DateTimeImmutable($o['vencimento_em'])>new DateTimeImmutable($r['_agora']))throw new RuntimeException('Cartao somente pode ser cobrado no vencimento.');
   if($o['asaas_payment_id']){$this->db->commit();return $o;}
   $q=$this->db->prepare('SELECT asaas_customer_id FROM obrigacoes_reserva WHERE reserva_id=:r AND numero=0');$q->execute(['r'=>$id]);$customer=$q->fetchColumn();if(!$customer)throw new RuntimeException('Entrada sem pagador reconciliado.');$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  $client=$this->client??=new AsaasHttpClient();
  $days=(int)ceil((InstallmentDelinquencyService::deadline($o,$cutoff)->getTimestamp()-(new DateTimeImmutable($o['vencimento_em']))->getTimestamp())/86400);
  $payload=['customer'=>$customer,'billingType'=>$o['forma_pagamento'],'value'=>PrecificacaoReservaService::centavosParaDecimal($o['total_centavos']),'dueDate'=>(new DateTimeImmutable($o['vencimento_em']))->format('Y-m-d'),'description'=>'Reserva #'.$id.' parcela '.$o['numero'],'interest'=>['value'=>0],'fine'=>['value'=>0]];
  if($o['forma_pagamento']==='BOLETO')$payload['daysAfterDueDateToRegistrationCancellation']=max(0,$days);
  if($o['forma_pagamento']==='CREDIT_CARD')(new ReservationCardVault($this->db))->credentials((int)$id,$client->accountScope(),$customer);
  $result=(new FinancialOperationService($client->accountScope(),$this->db))->executar('cobranca','reserva-obrigacao:'.$obligation,1,$payload,
   function(string $ref,array $p)use($client):array{$found=[];$offset=0;do{$page=$client->listarCobrancas(['externalReference'=>$ref,'limit'=>100,'offset'=>$offset]);foreach($page['data']??[] as $x){if(($x['externalReference']??'')!==$ref)continue;$this->assertPayment($x,$p,$ref);$found[]=$x;}$offset+=100;}while(!empty($page['hasMore']));return $found;},
   fn(string $ref,array $p):array=>$client->criarCobranca($p+['externalReference'=>$ref]+($o['forma_pagamento']==='CREDIT_CARD'?(new ReservationCardVault($this->db))->credentials((int)$id,$client->accountScope(),$customer):[])),
   function()use($id,$obligation,$cutoff):void{$this->db->beginTransaction();try{$r=ReservationLock::acquire($this->db,(int)$id);$q=$this->db->prepare('SELECT estado,cancelamento_em FROM obrigacoes_reserva WHERE id=:o');$q->execute(['o'=>$obligation]);$o=$q->fetch();if($r['status_reserva']!=='confirmada'||$o['estado']!=='pendente'||InstallmentDelinquencyService::deadline($o,$cutoff)<=new DateTimeImmutable($r['_agora']))throw new RuntimeException('Parcela cancelada antes de emitir.');$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}});
  $this->db->beginTransaction();try{$r=ReservationLock::acquire($this->db,(int)$id);$this->db->prepare('UPDATE obrigacoes_reserva SET asaas_payment_id=:p,invoice_url=:url,operacao_id=:op,asaas_customer_id=:customer WHERE id=:id')->execute(['p'=>$result['id'],'url'=>$result['invoiceUrl']??null,'op'=>$result['_operation_id'],'customer'=>$customer,'id'=>$obligation]);if(in_array($r['status_reserva'],ReservaStatusService::FINAIS,true))$this->cancelJob((int)$id,$obligation);$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  return array_merge($o,['asaas_payment_id'=>$result['id'],'invoice_url'=>$result['invoiceUrl']??null]);
 }
 public function processPayment(array $event,array $payment,string $type):?array
 {
  $q=$this->db->prepare("SELECT o.reserva_id FROM obrigacoes_reserva o LEFT JOIN operacoes_financeiras f ON f.tipo='cobranca' AND f.entidade='reserva-obrigacao:'||o.id WHERE o.asaas_payment_id=:p OR f.referencia=:ref LIMIT 1");$q->execute(['p'=>$payment['id']??'','ref'=>$payment['externalReference']??'']);$id=$q->fetchColumn();if($id===false)return null;
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,(int)$id);
   $q=$this->db->prepare("SELECT o.*,f.referencia,f.id AS intent_id,f.payload->>'customer' AS intent_customer FROM obrigacoes_reserva o JOIN operacoes_financeiras f ON f.tipo='cobranca' AND f.entidade='reserva-obrigacao:'||o.id AND f.referencia=:ref WHERE o.reserva_id=:r AND (o.asaas_payment_id IS NULL OR o.asaas_payment_id=:p) FOR UPDATE OF o");$q->execute(['p'=>$payment['id'],'ref'=>$payment['externalReference']??'','r'=>$id]);$o=$q->fetch();
   if(!$o)throw new RuntimeException('Obrigacao sem intencao vinculada.');
   $this->assertPayment($payment,['customer'=>$o['intent_customer'],'billingType'=>$o['forma_pagamento'],'value'=>PrecificacaoReservaService::centavosParaDecimal($o['total_centavos'])],$o['referencia']);
   $this->db->prepare('UPDATE obrigacoes_reserva SET asaas_payment_id=:p,operacao_id=:op,asaas_customer_id=:c WHERE id=:id')->execute(['p'=>$payment['id'],'op'=>$o['intent_id'],'c'=>$o['intent_customer'],'id'=>$o['id']]);
   $this->db->prepare('UPDATE reservas SET id_cobranca_asaas=:p WHERE id=:r AND id_cobranca_asaas IS NULL')->execute(['p'=>$payment['id'],'r'=>$id]);
   if($r['modalidade']==='entrada_parcelamento'&&$r['entrada_confirmada_em']!==null){(new InstallmentDelinquencyService($this->db))->cancelIfDue((int)$id);$r=ReservationLock::acquire($this->db,(int)$id);}
   $confirmed=in_array($type,['PAYMENT_CONFIRMED','PAYMENT_RECEIVED'],true);$reversed=str_contains($type,'REFUND')||str_contains($type,'CHARGEBACK');
   if($reversed&&str_contains($type,'REFUND')){
    $result=(new ReservationSettlementService($this->db))->reconcileRefund($payment);
    if(in_array($result['estado'],['concluido','processando'],true)){if($own)$this->db->commit();return ['matched'=>true,'status'=>'processado'];}
   }
   if($reversed){$this->db->prepare("UPDATE obrigacoes_reserva SET estado='conciliacao_manual' WHERE id=:o")->execute(['o'=>$o['id']]);$this->db->prepare("UPDATE reservas SET divergencia_pagamento=TRUE WHERE id=:r")->execute(['r'=>$id]);if($own)$this->db->commit();return ['matched'=>true,'status'=>'divergente'];}
   if(!$confirmed||$o['estado']==='estornada'){if($own)$this->db->commit();return ['matched'=>true,'status'=>'processado'];}
   if(!in_array($payment['status']??'', ['CONFIRMED','RECEIVED'],true))throw new RuntimeException('Estado externo nao confirma pagamento.');
   $previouslyPaid=$o['pago_centavos']===$o['total_centavos'];
   $late=in_array($r['status_reserva'],ReservaStatusService::FINAIS,true)||($r['status_reserva']==='aguardando_pagamento'&&new DateTimeImmutable($r['expira_em'])<=new DateTimeImmutable($r['_agora']));
   if($late&&$r['status_reserva']==='aguardando_pagamento')(new ReservaStatusService($this->db))->transicionar((int)$id,'expirada',['motivo'=>'Pagamento recebido apos encerramento do checkout','origem'=>'webhook','responsavel_tipo'=>'webhook']);
   // Record real money even if the hold has expired, but never revive the reservation.
   $paidAt=(new DateTimeImmutable((string)($payment['clientPaymentDate']??$payment['paymentDate']??$payment['confirmedDate']??$r['_agora'])))->format(DATE_ATOM);
   $this->db->prepare("UPDATE obrigacoes_reserva SET estado=CASE WHEN estado IN ('estornada','conciliacao_manual') THEN estado ELSE 'paga' END,pago_centavos=total_centavos,inadimplencia_estado='regular',pago_em=COALESCE(pago_em,:paid),liquidado_em=CASE WHEN :settled THEN COALESCE(liquidado_em,clock_timestamp()) ELSE liquidado_em END,atualizado_em=clock_timestamp() WHERE id=:id")->execute(['paid'=>$paidAt,'settled'=>$payment['status']==='RECEIVED','id'=>$o['id']]);
   $this->db->prepare("INSERT INTO pagamentos(reserva_id,usuario_id,valor,forma_pagamento,status_pagamento,id_transacao_asaas,data_pagamento,obrigacao_id) VALUES(:r,:u,:v,:m,'pago',:p,:d,:obligation) ON CONFLICT(id_transacao_asaas) DO UPDATE SET status_pagamento=CASE WHEN pagamentos.status_pagamento='estornado' THEN 'estornado' ELSE 'pago' END,data_pagamento=COALESCE(pagamentos.data_pagamento,EXCLUDED.data_pagamento)")->execute(['r'=>$id,'u'=>$r['usuario_id'],'v'=>PrecificacaoReservaService::centavosParaDecimal($o['total_centavos']),'m'=>$o['forma_pagamento']==='PIX'?'pix':($o['forma_pagamento']==='BOLETO'?'boleto':'cartao_credito'),'p'=>$payment['id'],'d'=>$paidAt,'obligation'=>$o['id']]);

   if($o['estado']==='conciliacao_manual'){if($own)$this->db->commit();return ['matched'=>true,'status'=>'divergente'];}
   if($previouslyPaid&&in_array($r['status_reserva'],ReservaStatusService::FINAIS,true)){if($own)$this->db->commit();return ['matched'=>true,'status'=>'processado'];}
   if($late){$this->db->prepare("UPDATE reservas SET divergencia_pagamento=TRUE WHERE id=:r")->execute(['r'=>$id]);$this->db->prepare("UPDATE obrigacoes_reserva SET estado='conciliacao_manual' WHERE id=:id")->execute(['id'=>$o['id']]);if($own)$this->db->commit();return ['matched'=>true,'status'=>'divergente'];}
   if($r['modalidade']==='entrada_parcelamento'){
    if($o['numero']===0&&$o['forma_pagamento']==='CREDIT_CARD')(new ReservationCardVault($this->db))->capture($r,$payment,$o['intent_customer']);
    if($o['numero']===0)(new InstallmentScheduleService($this->db))->activate($r,new DateTimeImmutable($paidAt));
    $q=$this->db->prepare('SELECT EXISTS(SELECT 1 FROM obrigacoes_reserva WHERE reserva_id=:r AND pago_centavos<total_centavos)');$q->execute(['r'=>$id]);$state=$q->fetchColumn()?'parcialmente_pago':'pago';
    $this->db->prepare('UPDATE reservas SET status_pagamento=:s WHERE id=:r')->execute(['s'=>$state,'r'=>$id]);
   }
   if($r['modalidade']==='integral')$this->db->prepare("UPDATE reservas SET status_pagamento='pago' WHERE id=:r AND status_pagamento NOT IN ('estornado','reembolso_processando','parcialmente_estornado')")->execute(['r'=>$id]);
   if($r['status_reserva']==='aguardando_pagamento')(new ReservaStatusService($this->db))->transicionar((int)$id,'confirmada',['motivo'=>$r['modalidade']==='integral'?'Pagamento integral confirmado':'Entrada confirmada e cronograma ativado','origem'=>'webhook','responsavel_tipo'=>'webhook']);
   $this->db->prepare("UPDATE repasses_reservas SET pagamento_id=(SELECT id FROM pagamentos WHERE id_transacao_asaas=:p),status_local=CASE WHEN repasse_liberavel_em<=clock_timestamp() THEN 'liberado_para_repasse' ELSE 'aguardando_liberacao' END WHERE reserva_id=:r AND status_local IN ('aguardando_pagamento','aguardando_liberacao')")->execute(['p'=>$payment['id'],'r'=>$id]);
   $this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave,proxima_tentativa_em) VALUES(:r,:o,'repasse',:k,:due) ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$id,'o'=>$o['id'],'k'=>'repasse:'.$o['id'],'due'=>$r['modalidade']==='integral'?$r['repasse_liberavel_em']:$r['_agora']]);
   if($own)$this->db->commit();return ['matched'=>true,'status'=>'processado'];
  }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function reconcile(int $obligation):array
 {
  $q=$this->db->prepare("SELECT o.*,f.referencia,f.payload,f.id AS intent_id FROM obrigacoes_reserva o JOIN operacoes_financeiras f ON f.tipo='cobranca' AND f.entidade='reserva-obrigacao:'||o.id WHERE o.id=:id");$q->execute(['id'=>$obligation]);$o=$q->fetch();if(!$o)return ['estado'=>'sem_intencao'];
  $client=$this->client??=new AsaasHttpClient();$payload=json_decode($o['payload'],true,512,JSON_THROW_ON_ERROR);
  if($o['asaas_payment_id'])$payment=$client->consultarCobranca($o['asaas_payment_id']);
  else{
   $matches=[];$offset=0;do{$page=$client->listarCobrancas(['externalReference'=>$o['referencia'],'limit'=>100,'offset'=>$offset]);foreach($page['data']??[] as $p)if(($p['externalReference']??'')===$o['referencia'])$matches[]=$p;$offset+=100;}while(!empty($page['hasMore']));
   if(count($matches)!==1)return ['estado'=>'conciliacao_manual'];$payment=$matches[0];
  }
  $this->assertPayment($payment,$payload,$o['referencia']);
  $q=$this->db->prepare('SELECT 1 FROM reembolsos_reservas WHERE asaas_payment_id=:p');$q->execute(['p'=>$payment['id']]);
  if($q->fetchColumn()){
   $settlement=new ReservationSettlementService($this->db,$client);
   $payment=$settlement->paymentWithRefundHistory($payment);
   $settlement->reconcileRefund($payment);
  }
  (new FinancialOperationService($client->accountScope(),$this->db))->executar('cobranca','reserva-obrigacao:'.$obligation,1,$payload,fn()=>[$payment],fn()=>throw new RuntimeException('Conciliacao somente leitura nao envia cobranca.'));
  $this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,(int)$o['reserva_id']);
   $this->db->prepare('UPDATE obrigacoes_reserva SET asaas_payment_id=:p,operacao_id=:op,asaas_customer_id=:c,invoice_url=:url,atualizado_em=clock_timestamp() WHERE id=:id')->execute(['p'=>$payment['id'],'op'=>$o['intent_id'],'c'=>$payload['customer'],'url'=>$payment['invoiceUrl']??null,'id'=>$obligation]);
   if($o['numero']===0)$this->db->prepare('UPDATE reservas SET id_cobranca_asaas=:p,link_pagamento_asaas=:url WHERE id=:r')->execute(['p'=>$payment['id'],'url'=>$payment['invoiceUrl']??null,'r'=>$o['reserva_id']]);
   if(in_array($r['status_reserva'],ReservaStatusService::FINAIS,true)&&$o['pago_centavos']===0)$this->cancelJob((int)$o['reserva_id'],$obligation);
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  // Linking a remotely found charge is reconciliation, not authenticated payment evidence.
  // Payment confirmation is applied only by the persisted webhook handler.
  if(in_array($payment['status']??'',['CONFIRMED','RECEIVED'],true))return ['estado'=>(int)$o['pago_centavos']===(int)$o['total_centavos']?'confirmacao_ja_persistida':'aguardando_webhook'];
  $type=match($payment['status']??''){'REFUNDED'=>'PAYMENT_REFUNDED',default=>'PAYMENT_UPDATED'};
  $this->processPayment([],$payment,$type);
  return ['estado'=>'conciliada'];
 }
 private function assertPayment(array $actual,array $expected,string $ref):void
 {if(($actual['externalReference']??'')!==$ref||($actual['customer']??'')!==$expected['customer']||($actual['billingType']??'')!==$expected['billingType']||PrecificacaoReservaService::decimalParaCentavos((string)($actual['value']??''))!==PrecificacaoReservaService::decimalParaCentavos((string)$expected['value']))throw new RuntimeException('Cobranca diverge da identidade, meio ou valor contratado.');}
 private function cancelJob(int $reservation,int $obligation):void{$this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave) VALUES(:r,:o,'cancelar_cobranca',:k) ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$reservation,'o'=>$obligation,'k'=>'cancelar:'.$obligation]);}
}
