<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Services\ReservationPricingEngine as Engine;
use App\Services\ReservationBillingService;
use App\Services\ReservationLock;
use App\Services\ReservationCardVault;
use App\Services\InstallmentScheduleService;
use App\Services\InstallmentDelinquencyService;
use App\Services\ReservaStatusService;
use App\Services\AsaasApiException;

$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$reference=new DateTimeImmutable('2026-01-10T12:00:00-03:00');$checkin=new DateTimeImmutable('2026-03-15T17:00:00Z');
$snapshot=Engine::calculate(20000,200,1000,3000,'entrada_parcelamento',$reference,$checkin);
$new=Engine::finalSchedule($snapshot,$reference);$legacy=$snapshot;$legacy['calendario_regra']='referencia_pagamento_entrada_ultimo_ajuste_D5';$old=Engine::finalSchedule($legacy,$reference);
$check($snapshot['calendario_regra']===Engine::CALENDAR_D6,'Novos snapshots identificam a regra D-6');
$check(end($snapshot['pagamentos'])['vencimento_provisorio']==='2026-03-09T00:00:00-03:00','Cotacao ja apresenta ultimo vencimento em D-6');
$check(end($new)['vencimento_em']==='2026-03-09T00:00:00-03:00','Ultimo vencimento originalmente em D-5 passa a D-6');
$check(end($old)['vencimento_em']==='2026-03-10T00:00:00-03:00','Snapshot existente conserva ultimo vencimento em D-5');
$check($snapshot['limite_ultima_parcela']==='2026-03-10T00:00:00-03:00'&&end($new)['cancelamento_em']===$snapshot['limite_ultima_parcela'],'D-5 permanece limite absoluto');
$check($new[1]['vencimento_em']===$old[1]['vencimento_em']&&array_column($new,'total_centavos')===array_column($old,'total_centavos'),'Demais vencimentos, quantidades e valores permanecem iguais');
$sameDay=Engine::calculate(20000,200,1000,3000,'entrada_parcelamento',$reference,new DateTimeImmutable('2026-03-16T02:59:59Z'));
$check($sameDay['limite_ultima_parcela']===$snapshot['limite_ultima_parcela'],'Corte usa dia local de Sao Paulo, nao o dia UTC');
$nextDay=Engine::calculate(20000,200,1000,3000,'entrada_parcelamento',$reference,new DateTimeImmutable('2026-03-16T03:00:00Z'));
$check($nextDay['limite_ultima_parcela']==='2026-03-11T00:00:00-03:00','Virada de dia UTC/local avanca corte exatamente um dia');
$last=end($new);$last['pago_centavos']=0;
$check(InstallmentDelinquencyService::stage($last,true,new DateTimeImmutable($last['vencimento_em']))==='atrasada','Falha em D-6 nao antecipa cancelamento');
$check(InstallmentDelinquencyService::stage($last,true,new DateTimeImmutable($snapshot['limite_ultima_parcela']))==='cancelamento_definitivo','Falha nao quitada cancela exatamente em D-5');
$last['pago_centavos']=$last['total_centavos'];$check(InstallmentDelinquencyService::stage($last,true,new DateTimeImmutable($snapshot['limite_ultima_parcela']))==='regular','Quitacao em D-6 permanece regular em D-5');

$db=Database::getConnection();$f=new FinancialFixture($db);$gateway=new FinancialGatewayFake($f->tag);$billing=new ReservationBillingService($db,$gateway);
// Seed synthetic historical contracts; never change the machine or database clock.
$seed=static function(int $offset)use(&$f,$db,&$gateway):array{
 $quote=$f->quote($offset);$base=$quote['detalhes'];$cutoff=Engine::cutoff(new DateTimeImmutable($quote['data_inicio'].'T00:00:00-03:00'));
 $reference=$cutoff->modify('first day of this month')->modify('-2 months');
 $reference=$reference->setDate((int)$reference->format('Y'),(int)$reference->format('m'),min((int)$cutoff->format('d'),(int)$reference->format('t')));
 if(Engine::monthlyDate($reference,2)!=$cutoff){$reference=$cutoff->modify('first day of this month')->modify('-6 months');$reference=$reference->setDate((int)$reference->format('Y'),(int)$reference->format('m'),(int)$cutoff->format('d'));}
 $s=array_merge($base,Engine::calculate($base['valor_hospedagem_centavos'],$base['taxa_operacional_centavos'],1000,3000,'entrada_parcelamento',$reference,new DateTimeImmutable($quote['data_inicio'])),['forma_pagamento'=>'CREDIT_CARD']);$s['quantidade_parcelas']=$s['quantidade_pagamentos'];
 $db->prepare('UPDATE cotacoes_reserva SET cancelada_em=clock_timestamp() WHERE id=:id')->execute(['id'=>$quote['id']]);
 $q=$db->prepare("WITH t AS (SELECT clock_timestamp() AS now) INSERT INTO cotacoes_reserva(id,usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,forma_pagamento,quantidade_parcelas,detalhes,criado_em,expira_em,versao_snapshot,modalidade) SELECT gen_random_uuid(),usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,'CREDIT_CARD',:n,CAST(:s AS jsonb),t.now,t.now+INTERVAL '15 minutes',2,'entrada_parcelamento' FROM cotacoes_reserva CROSS JOIN t WHERE id=:q RETURNING *");
 $q->execute(['n'=>$s['quantidade_pagamentos'],'s'=>json_encode($s,JSON_THROW_ON_ERROR),'q'=>$quote['id']]);$created=$q->fetch();$id=$f->consume($created);
 $billing=new ReservationBillingService($db,$gateway);$entry=$billing->issue($id,$f->users['cliente'],true,'127.0.0.1');
 $p=$gateway->payments[$entry['asaas_payment_id']];$p['status']='RECEIVED';$p['clientPaymentDate']=$reference->format('Y-m-d');$p['creditCard']=['creditCardToken'=>'synthetic-d6-token'];
 // Settle the historical entry, then build the current-version schedule under its lock.
 $db->beginTransaction();$r=ReservationLock::acquire($db,$id);
 $db->prepare("UPDATE obrigacoes_reserva SET estado='paga',pago_centavos=total_centavos,pago_em=:p,liquidado_em=:p WHERE id=:id")->execute(['p'=>$reference->format(DATE_ATOM),'id'=>$entry['id']]);
 (new ReservationCardVault($db))->capture($r,$p,$p['customer']);(new InstallmentScheduleService($db))->activate($r,$reference);
 $db->prepare("UPDATE reservas SET status_pagamento='parcialmente_pago' WHERE id=:r")->execute(['r'=>$id]);
 (new ReservaStatusService($db))->transicionar($id,'confirmada',['origem'=>'sistema','responsavel_tipo'=>'sistema','motivo'=>'Fixture sintetica D-6']);
 $db->prepare("UPDATE obrigacoes_reserva SET estado='paga',pago_centavos=total_centavos,pago_em=:p,liquidado_em=:p WHERE reserva_id=:r AND numero>0 AND numero<:last")->execute(['p'=>$reference->format(DATE_ATOM),'r'=>$id,'last'=>$s['quantidade_parcelas_saldo']]);$db->commit();
 $last=$db->query('SELECT * FROM obrigacoes_reserva WHERE reserva_id='.$id.' ORDER BY numero DESC LIMIT 1')->fetch();return [$id,$last,$s];
};
try{
 [$id,$o,$s]=$seed(6);$check((new DateTimeImmutable($o['vencimento_em']))->format('Y-m-d')===date('Y-m-d'),'Fixture de novo contrato vence hoje em D-6');
 $issued=$billing->issueScheduled((int)$o['id']);$p=$gateway->payments[$issued['asaas_payment_id']];$p['status']='CONFIRMED';$p['confirmedDate']=date('Y-m-d');$gateway->payments[$p['id']]=$p;
 $billing->reconcile((int)$o['id']);$billing->reconcile((int)$o['id']);
 $check($db->query('SELECT status_pagamento FROM reservas WHERE id='.$id)->fetchColumn()==='pago','Captura confirmada em D-6 quita reserva sem duplicidade');
 $check(!(new InstallmentDelinquencyService($db))->cancelIfDue($id),'Pagamento em D-6 nao cancela contrato');
 // Use another fixture property for the overlapping D-5 failure case.
 $paidFixture=$f;$f=new FinancialFixture($db);$gateway=new FinancialGatewayFake($f->tag);
 [$captureFailed,$captureDue]=$seed(6);
 $gateway->beforePayment=static function():void{throw new AsaasApiException('Falha sintetica de captura',400,'invalid_creditCard');};
 try{(new ReservationBillingService($db,$gateway))->issueScheduled((int)$captureDue['id']);throw new LogicException('Captura deveria falhar');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'HTTP 400'),'Executor tenta captura em D-6 e registra recusa do gateway');}
 $check(!(new InstallmentDelinquencyService($db))->cancelIfDue($captureFailed),'Recusa efetiva em D-6 conserva janela ate D-5');
 $check($db->query('SELECT pago_centavos FROM obrigacoes_reserva WHERE id='.(int)$captureDue['id'])->fetchColumn()===0,'Recusa de captura nao produz pagamento');
 $failedCaptureFixture=$f;$f=new FinancialFixture($db);$gateway=new FinancialGatewayFake($f->tag);
 [$failed,$due,$s]=$seed(5);
 $gateway->beforePayment=static function():void{throw new AsaasApiException('Falha sintetica de captura',400,'invalid_creditCard');};
 try{(new App\Services\FinancialOperationService($gateway->scope,$db))->executar('cobranca','reserva-obrigacao:'.$due['id'],1,['billingType'=>'CREDIT_CARD','value'=>'15.00'],fn()=>[],fn($ref,$p)=>$gateway->criarCobranca($p+['externalReference'=>$ref]));throw new LogicException('Falha nao registrada');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'HTTP 400'),'Falha historica de captura permanece recusada, sem baixa');}
 $check(new DateTimeImmutable($due['vencimento_em'])<new DateTimeImmutable($due['cancelamento_em']),'Falha de D-6 possui limite separado em D-5');
 $check((new InstallmentDelinquencyService($db))->cancelIfDue($failed),'Ultima parcela nao paga cancela definitivamente em D-5');
 $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$failed)->fetchColumn()==='cancelada','Reserva nao permanece confirmada apos D-5');
 $check($db->query('SELECT estado FROM autorizacoes_pagamento_reserva WHERE reserva_id='.$failed)->fetchColumn()==='revogada','Cancelamento em D-5 revoga token e impede nova captura');
}finally{if(isset($paidFixture))$paidFixture->close();if(isset($failedCaptureFixture))$failedCaptureFixture->close();$f->close();}
