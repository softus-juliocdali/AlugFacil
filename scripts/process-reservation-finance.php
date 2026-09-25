<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Core\Database;use App\Services\ReservaStatusService;use App\Services\ReservationSettlementService;
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Runner atual restrito ao banco local.');
$db=Database::getConnection();$live=in_array('--live',$argv,true);$service=new ReservationSettlementService($db);
if(!$live){$count=$db->query("SELECT COUNT(*) FROM tarefas_financeiras_reserva WHERE estado IN ('pendente','erro','processando')")->fetchColumn();echo "Dry-run: $count tarefas; use --live para processar no Sandbox.\n";exit;}
\App\Services\AsaasEnvironment::assertCredentials(true);
$billing=new \App\Services\ReservationBillingService($db);
$pending=$db->query("SELECT o.id FROM obrigacoes_reserva o WHERE EXISTS(SELECT 1 FROM operacoes_financeiras f WHERE f.tipo='cobranca' AND f.entidade='reserva-obrigacao:'||o.id) AND (o.estado IN ('pendente','cancelada','conciliacao_manual') OR o.liquidado_em IS NULL OR EXISTS(SELECT 1 FROM autorizacoes_pagamento_reserva a WHERE a.reserva_id=o.reserva_id AND a.estado='aguardando_autorizacao')) ORDER BY o.atualizado_em,o.id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
foreach($pending as $id){try{$billing->reconcile((int)$id);}catch(Throwable){echo "Obrigacao $id: conciliacao pendente.\n";}}
$expired=(new ReservaStatusService($db))->expirarVencidas(100);echo "Checkouts expirados: $expired\n";
$cancelled=(new \App\Services\InstallmentDelinquencyService($db))->run(100);echo "Cancelamentos por inadimplencia: $cancelled\n";
$ids=$db->query("SELECT id FROM tarefas_financeiras_reserva WHERE estado IN ('pendente','erro','processando') AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em<=clock_timestamp()) ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
foreach($ids as $id){$q=$db->prepare('SELECT pg_try_advisory_lock(741074,CAST(:id AS integer))');$q->execute(['id'=>$id]);if(!$q->fetchColumn())continue;
 try{
  $q=$db->prepare('SELECT * FROM tarefas_financeiras_reserva WHERE id=:id');$q->execute(['id'=>$id]);$job=$q->fetch();if(!in_array($job['estado'],['pendente','erro','processando'],true))continue;
  $db->prepare("UPDATE tarefas_financeiras_reserva SET estado='processando',tentativas=tentativas+1,atualizado_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$id]);
  $result=match($job['tipo']){'emitir_parcela'=>array_merge($billing->issueScheduled((int)$job['obrigacao_id']),['estado'=>'concluido']),'repasse'=>$service->payout((int)$job['obrigacao_id']),'cancelar_cobranca'=>$service->cancelPayment((int)$job['obrigacao_id']),'reembolso'=>$service->refund((int)substr($job['chave'],7)),default=>['estado'=>'manual']};
  $state=match($result['estado']){'concluido'=>'concluida','manual','falhou','cancelado'=>'manual',default=>'pendente'};
  $db->prepare("UPDATE tarefas_financeiras_reserva SET estado=:s,erro_codigo=NULL,proxima_tentativa_em=clock_timestamp()+INTERVAL '60 seconds',atualizado_em=clock_timestamp() WHERE id=:id")->execute(['s'=>$state,'id'=>$id]);echo "Tarefa $id: $state\n";
 }catch(Throwable $e){$db->prepare("UPDATE tarefas_financeiras_reserva SET estado='erro',erro_codigo=:e,proxima_tentativa_em=clock_timestamp()+INTERVAL '60 seconds',atualizado_em=clock_timestamp() WHERE id=:id")->execute(['e'=>$e instanceof PDOException?'PERSISTENCIA':'VERIFICACAO_FINANCEIRA_PENDENTE','id'=>$id]);echo "Tarefa $id: aguardando nova verificacao.\n";}
 finally{$db->prepare('SELECT pg_advisory_unlock(741074,CAST(:id AS integer))')->execute(['id'=>$id]);}
}
