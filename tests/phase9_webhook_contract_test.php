<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Models\AsaasWebhookEvento;
use App\Services\AsaasWebhookService;
use App\Services\ReservationBillingService;
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$check(AsaasWebhookEvento::validEventId('evt_synthetic&12345'),'ID composto documentado pelo Asaas e aceito');
foreach([null,[],"evt_bad\n",'evt_<script>',str_repeat('a',121)] as $invalid)$check(!AsaasWebhookEvento::validEventId($invalid),'ID invalido permanece recusado');
$events=AsaasWebhookService::subscriptionEvents();
foreach(['PAYMENT_CONFIRMED','PAYMENT_CREDIT_CARD_CAPTURE_REFUSED','PAYMENT_REFUND_DENIED','TRANSFER_DONE','TRANSFER_FAILED','TRANSFER_CANCELLED'] as $event)$check(in_array($event,$events,true),'Configuracao inclui '.$event);
$check(count($events)===count(array_unique($events))&&!in_array('PAYMENT_PENDING',$events,true)&&!in_array('PAYMENT_REFUND_REQUESTED',$events,true),'Assinatura nao inclui eventos duplicados ou nao documentados');
$db=Database::getConnection();$f=new FinancialFixture($db);$gateway=new FinancialGatewayFake($f->tag);$ids=[];
try{
 $reservation=$f->consume($f->quote(90,'integral','CREDIT_CARD'));$billing=new ReservationBillingService($db,$gateway);$entry=$billing->issue($reservation,$f->users['cliente']);
 $p=$gateway->payments[$entry['asaas_payment_id']];$model=new AsaasWebhookEvento($db);$service=new AsaasWebhookService($db);
 foreach(['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED','PAYMENT_REPROVED_BY_RISK_ANALYSIS'] as $i=>$type){
  $event=['id'=>'evt_'.$f->tag.'&'.$i,'event'=>$type,'payment'=>$p];$json=json_encode($event,JSON_THROW_ON_ERROR);$received=$model->receber($event,$json);$ids[]=(int)$received['event']['id'];
  $check($service->processarPorId(end($ids))==='processado','Falha de cartao recebida e processada: '.$type);
 }
 $check($db->query('SELECT estado FROM obrigacoes_reserva WHERE id='.$entry['id'])->fetchColumn()==='pendente','Falha de captura nao quita obrigacao');
 $check((int)$db->query('SELECT COUNT(*) FROM pagamentos WHERE reserva_id='.$reservation)->fetchColumn()===0,'Falha de captura nao registra dinheiro recebido');
 $check((int)$db->query("SELECT COUNT(*) FROM tarefas_financeiras_reserva WHERE reserva_id=$reservation AND tipo='repasse'")->fetchColumn()===0,'Falha de captura nao libera repasse');
}finally{foreach($ids as $id)$db->prepare('DELETE FROM asaas_webhook_eventos WHERE id=:id')->execute(['id'=>$id]);$f->close();}
