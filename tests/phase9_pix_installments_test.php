<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Models\AsaasWebhookEvento;
use App\Services\AsaasWebhookService;
use App\Services\ReservationBillingService;
use App\Services\ReservationSettlementService;

$db=Database::getConnection();$f=new FinancialFixture($db);$gateway=new FinancialGatewayFake($f->tag);
$billing=new ReservationBillingService($db,$gateway);$settlement=new ReservationSettlementService($db,$gateway);
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$limits=$db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();
$events=[];
try{
 $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000,entrada_maxima_bps=8000 WHERE id=1');
 $db->prepare('UPDATE configuracoes_comerciais_imoveis SET aceita_parcelamento=TRUE,entrada_bps=3000 WHERE chacara_id=:c')->execute(['c'=>$f->property]);
 $quote=$f->quote(180,'entrada_parcelamento','PIX');$id=$f->consume($quote);$entry=$billing->issue($id,$f->users['cliente']);
 try{(new App\Services\CancelamentoReservaService($db))->cancelarUsuario($id,$f->users['cliente']);throw new LogicException('Cancelamento voluntario permitido');}catch(RuntimeException $e){$check($e->getMessage()==='Reserva parcelada nao permite cancelamento voluntario.','Parcelado nao permite cancelamento voluntario mesmo antes da entrada');}
 $db->beginTransaction();try{$db->prepare("UPDATE reservas SET status_reserva='cancelada',tipo_cancelamento='voluntario' WHERE id=:r")->execute(['r'=>$id]);throw new LogicException('Banco permitiu cancelamento voluntario');}catch(PDOException $e){$check($e->getCode()==='23514','Banco tambem impede cancelamento voluntario antes da entrada');}finally{$db->rollBack();}
 $check($gateway->payments[$entry['asaas_payment_id']]['billingType']==='PIX','Entrada usa cobranca PIX independente');
 $r=$db->query('SELECT * FROM reservas WHERE id='.$id)->fetch();
 $check(strtotime($r['expira_em'])===strtotime($quote['expira_em']),'PIX preserva hold original de quinze minutos');
 $payment=$gateway->payments[$entry['asaas_payment_id']];$payment['status']='RECEIVED';
 // A synthetic historical payment date makes the first monthly obligation due now.
 $payment['clientPaymentDate']=(new DateTimeImmutable('today'))->modify('-1 month')->format('Y-m-d');
 $gateway->payments[$payment['id']]=$payment;$billing->reconcile((int)$entry['id']);
 $parts=$db->query('SELECT * FROM obrigacoes_reserva WHERE reserva_id='.$id.' AND numero>0 ORDER BY numero')->fetchAll();$part=$parts[0];
 $job=$db->query("SELECT proxima_tentativa_em FROM tarefas_financeiras_reserva WHERE chave='emitir:".$part['id']."'")->fetchColumn();
 $check(new DateTimeImmutable($job)<=new DateTimeImmutable('now'),'Executor pode disponibilizar PIX mensais apos confirmar a entrada');
 $check($settlement->payout((int)$entry['id'])['estado']==='concluido','Entrada PIX liquidada repassa antes da quitacao integral');
 $db->prepare('UPDATE configuracoes_comerciais_imoveis SET comissao_bps=2500 WHERE chacara_id=:c')->execute(['c'=>$f->property]);
 $config=require APP_ROOT.'/app/config/database.php';
 $other=new PDO('pgsql:host='.$config['DB_HOST'].';port='.$config['DB_PORT'].';dbname='.$config['DB_NAME'],$config['DB_USER'],$config['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[App\Core\TypedStatement::class]]);
 $contender=new ReservationBillingService($other,$gateway);$posts=$gateway->paymentPosts;
 $gateway->afterPayment=function(array $p)use($contender,$part,$billing,$check,$gateway):void{
  try{$contender->issueScheduled((int)$part['id']);throw new LogicException('Worker duplicou a cobranca');}catch(RuntimeException $e){$check($e->getMessage()==='Operacao financeira em andamento.','Dois workers da parcela disputam a mesma intencao duravel');}
  $p['status']='RECEIVED';$gateway->payments[$p['id']]=$p;
  $check($billing->processPayment([],$p,'PAYMENT_RECEIVED')['status']==='processado','Webhook da parcela antes da resposta HTTP encontra a intencao');
 };
 $issued=$billing->issueScheduled((int)$part['id']);$gateway->afterPayment=null;$again=$billing->issueScheduled((int)$part['id']);
 $check($gateway->paymentPosts===$posts+1&&$issued['asaas_payment_id']===$again['asaas_payment_id'],'Workers e reexecucao produzem uma unica cobranca');
 $p=$gateway->payments[$issued['asaas_payment_id']];
 $check(!isset($p['subscription'],$p['pixAutomaticAuthorizationId'])&&$p['interest']['value']===0&&$p['fine']['value']===0,'PIX mensal nao simula debito automatico nem aplica juros ou multa');
 $webhook=['id'=>'evt_'.$f->tag,'event'=>'PAYMENT_RECEIVED','payment'=>$p];$raw=json_encode($webhook,JSON_THROW_ON_ERROR);
 $receiver=new AsaasWebhookEvento($db);$received=$receiver->receber($webhook,$raw);$events[]=$received['event']['id'];$duplicate=$receiver->receber($webhook,$raw);
 $check($received['created']&&!$duplicate['created']&&!$duplicate['divergent'],'Recepcao de webhook repetido e idempotente');
 $processor=new AsaasWebhookService($db);$check($processor->processarPorId((int)$events[0])==='processado'&&$processor->processarPorId((int)$events[0])==='ignorado','Fila processa mesmo evento uma unica vez');
 $p['status']='OVERDUE';$billing->processPayment([],$p,'PAYMENT_OVERDUE');
 $check($db->query('SELECT estado FROM obrigacoes_reserva WHERE id='.$part['id'])->fetchColumn()==='paga','Vencimento fora de ordem nao desfaz pagamento');
 $check((int)$db->query('SELECT COUNT(*) FROM pagamentos WHERE obrigacao_id='.$part['id'])->fetchColumn()===1,'Webhook precoce e duplicado nao duplicam baixa');
 $fresh=$db->query('SELECT * FROM obrigacoes_reserva WHERE id='.$part['id'])->fetch();
 $check($fresh['comissao_centavos']===$part['comissao_centavos']&&$fresh['proprietario_centavos']===$part['proprietario_centavos'],'Mudanca administrativa preserva comissao e liquido contratados');
 $transfers=$gateway->transferPosts;$settlement->payout((int)$part['id']);$settlement->payout((int)$part['id']);
 $check($gateway->transferPosts===$transfers+1,'Parcela PIX paga gera um unico repasse');
 $next=$parts[1];$future=$billing->issueScheduled((int)$next['id']);
 $check($gateway->payments[$future['asaas_payment_id']]['dueDate']===(new DateTimeImmutable($next['vencimento_em']))->format('Y-m-d'),'PIX disponibilizado antecipadamente preserva vencimento mensal');
 $last=end($parts);$lastPayment=$billing->issueScheduled((int)$last['id']);
 $check(!empty($lastPayment['invoice_url']),'Ultimo PIX fica disponivel para quitacao antes de D-5');
}finally{
 foreach($events as $event)$db->prepare('DELETE FROM asaas_webhook_eventos WHERE id=:id')->execute(['id'=>$event]);
 $db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max WHERE id=1')->execute(['min'=>$limits['entrada_minima_bps'],'max'=>$limits['entrada_maxima_bps']]);$f->close();
}
