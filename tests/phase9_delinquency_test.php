<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Services\InstallmentDelinquencyService;
use App\Services\InstallmentScheduleService;
use App\Services\ReservationPricingEngine;
use App\Services\ReservationLock;
use App\Services\ReservaStatusService;

$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$o=['total_centavos'=>100,'pago_centavos'=>0,'vencimento_em'=>'2026-01-10T00:00:00-03:00','cancelamento_em'=>'2026-02-15T00:00:00-03:00'];
foreach(['2026-01-09T23:59:59-03:00'=>'pendente','2026-01-20T00:00:00-03:00'=>'atrasada','2026-02-10T00:00:00-03:00'=>'tolerancia','2026-02-14T23:59:59-03:00'=>'tolerancia','2026-02-15T00:00:00-03:00'=>'cancelamento_definitivo'] as $time=>$expected)$check(InstallmentDelinquencyService::stage($o,false,new DateTimeImmutable($time))===$expected,'Fronteira intermediaria '.$time.' = '.$expected);
$o['pago_centavos']=99;$check(InstallmentDelinquencyService::stage($o,true,new DateTimeImmutable($o['cancelamento_em']))==='cancelamento_definitivo','Saldo parcial da ultima parcela nao amplia D-5');
$o['pago_centavos']=100;$check(InstallmentDelinquencyService::stage($o,true,new DateTimeImmutable($o['cancelamento_em']))==='regular','Parcela integralmente paga nao causa cancelamento');

$db=Database::getConnection();$fixture=new FinancialFixture($db);$service=new InstallmentDelinquencyService($db);
// Historical contracts are seeded directly in the isolated local fixture. No gateway is called.
$historical=static function(int $offset,int $months,bool $legacy=false)use($db,$fixture):int{
 $quote=$fixture->quote($offset);$original=$quote['detalhes'];
 $reference=(new DateTimeImmutable('today'))->modify('first day of this month')->modify('-'.$months.' months');
 $reference=$reference->setDate((int)$reference->format('Y'),(int)$reference->format('m'),min((int)date('d'),(int)$reference->format('t')));
 $calculated=ReservationPricingEngine::calculate($original['valor_hospedagem_centavos'],$original['taxa_operacional_centavos'],1000,3000,'entrada_parcelamento',$reference,new DateTimeImmutable($quote['data_inicio']));
 $snapshot=array_merge($original,$calculated,['forma_pagamento'=>'BOLETO','quantidade_parcelas'=>$calculated['quantidade_pagamentos']]);
 if($legacy)$snapshot['calendario_regra']='referencia_pagamento_entrada_ultimo_ajuste_D5';
 $db->prepare('UPDATE cotacoes_reserva SET cancelada_em=clock_timestamp() WHERE id=:id')->execute(['id'=>$quote['id']]);
 $q=$db->prepare("WITH t AS (SELECT clock_timestamp() AS now) INSERT INTO cotacoes_reserva(id,usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,forma_pagamento,quantidade_parcelas,detalhes,criado_em,expira_em,versao_snapshot,modalidade) SELECT gen_random_uuid(),q.usuario_id,q.chacara_id,q.configuracao_financeira_id,q.versao_configuracao,q.data_inicio,q.data_fim,'BOLETO',:n,CAST(:snapshot AS jsonb),t.now,t.now+INTERVAL '15 minutes',2,'entrada_parcelamento' FROM cotacoes_reserva q CROSS JOIN t WHERE q.id=:id RETURNING *");
 $q->execute(['n'=>$calculated['quantidade_pagamentos'],'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'id'=>$quote['id']]);$created=$q->fetch();$id=$fixture->consume($created);$entry=$snapshot['pagamentos'][0];
 $db->beginTransaction();$r=ReservationLock::acquire($db,$id);
 $q=$db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,forma_pagamento,estado,pago_centavos,pago_em,liquidado_em) VALUES(:r,0,'entrada',:h,:o,:c,:p,:t,:due,'BOLETO','paga',:paid,:paid_at,:settled_at)");
 $q->execute(['r'=>$id,'h'=>$entry['hospedagem_centavos'],'o'=>$entry['taxa_operacional_centavos'],'c'=>$entry['comissao_centavos'],'p'=>$entry['proprietario_centavos'],'t'=>$entry['total_centavos'],'due'=>$created['expira_em'],'paid'=>$entry['total_centavos'],'paid_at'=>$reference->format(DATE_ATOM),'settled_at'=>$reference->format(DATE_ATOM)]);
 if(!$legacy)(new InstallmentScheduleService($db))->activate($r,$reference);
 else{
  // Reproduce an issued pre-fix contract without updating its immutable dates.
  $db->prepare('INSERT INTO cronogramas_reserva(reserva_id,referencia_entrada_em,limite_ultima_parcela,quantidade_parcelas,regra) VALUES(:r,:paid,:limit,:n,:rule)')->execute(['r'=>$id,'paid'=>$reference->format(DATE_ATOM),'limit'=>$snapshot['limite_ultima_parcela'],'n'=>$snapshot['quantidade_parcelas_saldo'],'rule'=>$snapshot['calendario_regra']]);
  $parts=ReservationPricingEngine::finalSchedule($snapshot,$reference);
  foreach($parts as $i=>$part){if($i===0)continue;$deadline=isset($parts[$i+1])?(new DateTimeImmutable($parts[$i+1]['vencimento_em']))->modify('+5 days')->format(DATE_ATOM):$snapshot['limite_ultima_parcela'];
   $db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,cancelamento_em,forma_pagamento) VALUES(:r,:n,'parcela',:h,:o,:c,:p,:t,:due,:deadline,'BOLETO')")->execute(['r'=>$id,'n'=>$i,'h'=>$part['hospedagem_centavos'],'o'=>$part['taxa_operacional_centavos'],'c'=>$part['comissao_centavos'],'p'=>$part['proprietario_centavos'],'t'=>$part['total_centavos'],'due'=>$part['vencimento_em'],'deadline'=>$deadline]);
  }
  $db->prepare('UPDATE reservas SET entrada_confirmada_em=:paid WHERE id=:r')->execute(['paid'=>$reference->format(DATE_ATOM),'r'=>$id]);
 }
 $db->prepare("UPDATE reservas SET status_pagamento='parcialmente_pago' WHERE id=:r")->execute(['r'=>$id]);
 (new ReservaStatusService($db))->transicionar($id,'confirmada',['origem'=>'sistema','responsavel_tipo'=>'sistema','motivo'=>'Contrato historico sintetico de teste']);$db->commit();return $id;
};
try {
 $grace=$historical(100,2);
 $check(!$service->cancelIfDue($grace),'Vencimento seguinte inicia tolerancia, sem cancelar antecipadamente');
 $rows=$db->query('SELECT * FROM obrigacoes_reserva WHERE reserva_id='.$grace.' AND numero>0 ORDER BY numero')->fetchAll();
 $check($rows[0]['inadimplencia_estado']==='tolerancia'&&$rows[1]['inadimplencia_estado']==='atrasada','Cada parcela tem seu proprio estado de atraso');
 $db->prepare("UPDATE obrigacoes_reserva SET pago_centavos=total_centavos,estado='paga',pago_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$rows[0]['id']]);
 $check(!$service->cancelIfDue($grace),'Quitacao durante tolerancia preserva reserva');
 $states=$db->query('SELECT inadimplencia_estado FROM obrigacoes_reserva WHERE reserva_id='.$grace.' AND numero>0 ORDER BY numero')->fetchAll(PDO::FETCH_COLUMN);
 $check($states[0]==='regular'&&$states[1]==='atrasada','Pagar uma parcela nao regulariza outra');

 $gateway=new FinancialGatewayFake($fixture->tag);$settlement=new \App\Services\ReservationSettlementService($db,$gateway);
 foreach([[110,3,false],[5,2,true]] as [$offset,$months,$last]) {
  $id=$historical($offset,$months);$paidBefore=(int)$db->query('SELECT SUM(pago_centavos) FROM obrigacoes_reserva WHERE reserva_id='.$id)->fetchColumn();
  $check($service->cancelIfDue($id),$last?'Ultima parcela cancela em D-5 sem tolerancia adicional':'Intermediaria cancela ao terminar tolerancia');
  $audit=$db->query('SELECT * FROM cancelamentos_inadimplencia_reserva WHERE reserva_id='.$id)->fetch();
  $check($audit['ultima_parcela']===$last&&$audit['recebido_centavos']===$paidBefore,'Auditoria identifica causa e preserva valores recebidos');
  $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$id)->fetchColumn()==='cancelada','Reserva definitivamente cancelada');
  $check((int)$db->query('SELECT COUNT(*) FROM reembolsos_reservas WHERE reserva_id='.$id)->fetchColumn()===0,'Inadimplencia nao cria estorno');
  $check((int)$db->query("SELECT COUNT(*) FROM obrigacoes_reserva WHERE reserva_id=$id AND estado='pendente'")->fetchColumn()===0,'Obrigacoes pendentes deixam de aceitar pagamento para manter a reserva');
  $q=$db->prepare("SELECT COUNT(*) FROM notificacoes WHERE chave_deduplicacao LIKE :prefix");$q->execute(['prefix'=>'inadimplencia:'.$id.':%']);$check((int)$q->fetchColumn()===2,'Hospede e proprietario recebem notificacao interna');
  $check(!$service->cancelIfDue($id),'Reprocessamento nao repete cancelamento');
  $posts=$gateway->transferPosts;
  $entry=(int)$db->query('SELECT id FROM obrigacoes_reserva WHERE reserva_id='.$id.' AND numero=0')->fetchColumn();
  $check($settlement->payout($entry)['estado']==='concluido','Entrada liquidada pode ser repassada depois de cancelar por inadimplencia');
  $check($settlement->payout($entry)['estado']==='concluido'&&$gateway->transferPosts===$posts+1,'Repasse adquirido nao duplica na reexecucao');
  $new=$fixture->quote($offset);$check(!empty($new['id']),'Datas liberadas para novo checkout');
 }
 $db->prepare('UPDATE cotacoes_reserva SET cancelada_em=clock_timestamp() WHERE chacara_id=:c AND consumida_em IS NULL AND cancelada_em IS NULL')->execute(['c'=>$fixture->property]);
 $legacy=$historical(5,2,true);
 $parts=$db->query('SELECT * FROM obrigacoes_reserva WHERE reserva_id='.$legacy.' AND numero>0 ORDER BY numero')->fetchAll();$last=end($parts);$first=$parts[0];
 $db->prepare("UPDATE obrigacoes_reserva SET estado='paga',pago_centavos=total_centavos,pago_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$last['id']]);
 $check(new DateTimeImmutable($first['cancelamento_em'])>new DateTimeImmutable('now'),'Fixture historica tem tolerancia nominal ainda futura');
 $cutoff=new DateTimeImmutable($db->query('SELECT limite_ultima_parcela FROM cronogramas_reserva WHERE reserva_id='.$legacy)->fetchColumn());
 $check(InstallmentDelinquencyService::stage($first,false,$cutoff->modify('-1 microsecond'),$cutoff,new DateTimeImmutable($last['vencimento_em']))!=='cancelamento_definitivo','Nao cancela um microssegundo antes do limite absoluto');
 $check(InstallmentDelinquencyService::stage($first,false,$cutoff,$cutoff,new DateTimeImmutable($last['vencimento_em']))==='cancelamento_definitivo','Em D-5 tolerancia historica nao prevalece');
 try{(new App\Services\ReservationBillingService($db,new FinancialGatewayFake($fixture->tag)))->issueScheduled((int)$first['id']);throw new LogicException('Emitiu apos D-5');}catch(RuntimeException $e){$check($e->getMessage()==='Parcela fora da janela de pagamento.','Executor rejeita parcela historica apos D-5');}
 $check($service->run()===1,'Executor cancela em D-5 mesmo com ultima parcela ja paga');
 $check($db->query('SELECT cancelamento_em FROM obrigacoes_reserva WHERE id='.$first['id'])->fetchColumn()===$first['cancelamento_em'],'Cancelamento nao reescreve calendario historico');
 $check(new DateTimeImmutable($db->query('SELECT limite_em FROM cancelamentos_inadimplencia_reserva WHERE reserva_id='.$legacy)->fetchColumn())==$cutoff,'Auditoria registra limite efetivo D-5');
 $check(!$service->cancelIfDue($legacy),'Cancelamento de contrato historico e idempotente');
} finally {$fixture->close();}
echo "Fase 9: fronteiras e cancelamento local verificados; nenhum recurso externo foi criado.\n";
