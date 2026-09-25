<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';require __DIR__.'/support/FinancialGatewayFake.php';require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;use App\Services\ReservationBillingService;use App\Services\ReservationSettlementService;use App\Services\CancelamentoReservaService;use App\Services\CheckoutQuoteService;use App\Services\FinancialDeadlinePolicy;use App\Services\PayerDocumentService;use App\Services\ReservationLock;
$db=Database::getConnection();$fixture=new FinancialFixture($db);$fake=new FinancialGatewayFake($fixture->tag);$billing=new ReservationBillingService($db,$fake);$settlement=new ReservationSettlementService($db,$fake);$user=$fixture->users['cliente'];
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $f,string $m)use($check):void{try{$f();}catch(PDOException $e){throw $e;}catch(RuntimeException|DomainException){$check(true,$m);return;}throw new RuntimeException($m);};
$confirmed=static function(array $o,string $status='RECEIVED')use($fake,$billing):array{$id=$o['asaas_payment_id'];$fake->payments[$id]['status']=$status;$fake->payments[$id]['paymentDate']=date('Y-m-d');return $billing->processPayment([],$fake->payments[$id],$status==='RECEIVED'?'PAYMENT_RECEIVED':'PAYMENT_CONFIRMED');};
try{
 $l=new DateTimeImmutable('2030-01-01T12:00:00-03:00');$check(FinancialDeadlinePolicy::mayCancel($l->modify('-1 microsecond'),$l)&&!FinancialDeadlinePolicy::mayCancel($l,$l)&&FinancialDeadlinePolicy::mayTransfer($l,$l),'Fronteira L sem lacuna ou sobreposicao');
 $q=$fixture->quote();$reject(fn()=>$fixture->quote(),'Segundo checkout no mesmo periodo recusado');
 try{$db->prepare("INSERT INTO disponibilidades(chacara_id,data,status) VALUES(:c,:d,'bloqueado')")->execute(['c'=>$fixture->property,'d'=>$q['data_inicio']]);throw new RuntimeException('Bloqueio ignorou hold');}catch(PDOException $e){$check($e->getCode()==='23514','Banco impede bloqueio manual sobre checkout');}
 $dates=(new App\Models\Chacara())->buscarDatasIndisponiveis($fixture->property,$q['data_inicio'],$q['data_fim']);$check(count($dates)===2,'Calendario mostra noites do hold e libera dia de saida');
 $rid=$fixture->consume($q);$o=$billing->issue($rid,$user);$again=$billing->issue($rid,$user);$check($fake->paymentPosts===1&&$again['asaas_payment_id']===$o['asaas_payment_id'],'Obrigacao e repeticao criam somente uma cobranca');
 $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$rid)->fetchColumn()==='aguardando_pagamento','Geracao da cobranca nao confirma reserva');
 $reject(fn()=>(new PayerDocumentService($db))->save($user,'PF',FinancialFixture::cpf()),'Documento usado no gateway nao muda silenciosamente');
 $bad=$fake->payments[$o['asaas_payment_id']];$bad['customer']='different';$bad['status']='RECEIVED';$reject(fn()=>$billing->processPayment([],$bad,'PAYMENT_RECEIVED'),'Webhook com cliente divergente rejeitado');
 $check($confirmed($o)['status']==='processado','Pagamento integral recebido confirma');$confirmed($o);
 $check((int)$db->query('SELECT COUNT(*) FROM pagamentos WHERE reserva_id='.$rid)->fetchColumn()===1,'Webhook repetido nao duplica pagamento');
 $reject(fn()=>$settlement->payout((int)$o['id']),'Pagamento liquidado antes de L nao e repassado');
 $cancel=(new CancelamentoReservaService($db))->cancelarUsuario($rid,$user,null,'Teste integral');
 $check($cancel['valor_reembolso_centavos']===20000,'Cancelamento solicita somente hospedagem, preservando taxa operacional');
 $refund=$settlement->refund($cancel['reembolso_id']);$check($refund['estado']==='processando','POST de estorno PENDING nao e anunciado como devolvido');
 $settlement->refund($cancel['reembolso_id']);$check($fake->refundPosts===1,'Reconciliacao de estorno nao repete POST');
  $description=$fake->payments[$o['asaas_payment_id']]['refunds'][0]['description'];$fake->payments[$o['asaas_payment_id']]['refunds'][0]['description']=null;
  $check($settlement->refund($cancel['reembolso_id'])['estado']==='manual','Gateway sem correlacao do refund exige conciliacao manual');
  $check($settlement->refund($cancel['reembolso_id'])['estado']==='manual'&&$fake->refundPosts===1,'Refund sem correlacao nao repete POST nem sai de revisao manual');
  $without=$fake->payments[$o['asaas_payment_id']];$without['refunds']=[];$check($settlement->reconcileRefund($without)['estado']==='manual','Lista vazia posterior conserva revisao manual');
  $fake->payments[$o['asaas_payment_id']]['refunds'][0]['description']=$description;
  $fake->payments[$o['asaas_payment_id']]['refunds'][0]['status']='FUTURE_STATUS';$check($settlement->reconcileRefund($fake->payments[$o['asaas_payment_id']])['estado']==='manual','Estado de estorno desconhecido exige revisao');
  $fake->payments[$o['asaas_payment_id']]['refunds'][0]['status']='DONE';$refund=$settlement->reconcileRefund($fake->payments[$o['asaas_payment_id']]);$check($refund['estado']==='concluido','Somente DONE correlacionado conclui estorno');
 $money=$db->query('SELECT status_pagamento FROM reservas WHERE id='.$rid)->fetchColumn();$confirmed($o);$check($db->query('SELECT status_pagamento FROM reservas WHERE id='.$rid)->fetchColumn()===$money,'Confirmacao antiga nao desfaz estorno concluido');
 $reject(fn()=>$settlement->payout((int)$o['id']),'Reserva cancelada nao pode repassar');
 $near=$fixture->consume($fixture->quote(1,'integral','CREDIT_CARD'));$card=$billing->issue($near,$user);$confirmed($card,'CONFIRMED');
 $reject(fn()=>$settlement->payout((int)$card['id']),'Cartao CONFIRMED sem liquidacao nao pode repassar');
 $reject(fn()=>(new CancelamentoReservaService($db))->cancelarUsuario($near,$user),'Cancelamento em ou depois de L recusado');
 $confirmed($card);$fake->balance='0.00';$reject(fn()=>$settlement->payout((int)$card['id']),'Saldo insuficiente adia repasse sem reduzir direito do proprietario');
 $check($fake->transferPosts===0,'Falta de saldo nao envia transferencia');$fake->balance='100000.00';$paid=$settlement->payout((int)$card['id']);$check($paid['estado']==='concluido'&&$fake->transferPosts===1,'Saldo recomposto permite unico repasse de hospedagem menos comissao');
 $settlement->payout((int)$card['id']);$check($fake->transferPosts===1,'Repeticao de repasse concluido nao cria transferencia');
 $check(reset($fake->transfers)['value']==='180.00','Tarifa do gateway nao desconta direito contratual de R$180');
 $early=$fixture->consume($fixture->quote(100));$fake->afterPayment=function(array $p)use($billing,$fake,$check):void{$p['status']='RECEIVED';$fake->payments[$p['id']]=$p;$check($billing->processPayment([],$p,'PAYMENT_RECEIVED')['status']==='processado','Webhook antes do retorno HTTP encontra intencao duravel');};$billing->issue($early,$user);$fake->afterPayment=null;
 $short=$fixture->consume($fixture->shortQuote(110));$late=$billing->issue($short,$user);usleep(5200000);$lateResult=$confirmed($late);$check($lateResult['status']==='divergente'&&$db->query('SELECT status_reserva FROM reservas WHERE id='.$short)->fetchColumn()==='expirada','Pagamento apos 15 minutos nao reativa reserva');
 $new=$fixture->quote(110);$check(!empty($new['id']),'Agenda liberada apos expiracao mesmo com pagamento tardio');
 $config=require APP_ROOT.'/app/config/database.php';$other=new PDO('pgsql:host='.$config['DB_HOST'].';port='.$config['DB_PORT'].';dbname='.$config['DB_NAME'],$config['DB_USER'],$config['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->beginTransaction();ReservationLock::acquire($db,$near);$other->beginTransaction();$s=$other->prepare('SELECT pg_try_advisory_xact_lock(:c)');$s->execute(['c'=>$fixture->property]);$check($s->fetchColumn()===false,'Outra conexao nao atravessa lock comum a cancelamento e repasse');$other->rollBack();$db->rollBack();
}finally{$fixture->close();}
echo "Fase 7: PostgreSQL e gateway simulado verificados; nenhuma chamada externa.\n";
