<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use Throwable;

/** Operational worker restricted to explicitly registered synthetic financial tests. */
final class SandboxFinancialWorker
{
 public function __construct(private PDO $db,private ?AsaasPaymentClientInterface $gateway=null){}
 public function run(int $limit=50):array
 {
  $registry=FinancialReleasePolicy::registry();$out=['webhooks'=>0,'reconciled'=>0,'expired'=>0,'delinquent'=>0,'jobs'=>0,'deferred'=>0,'transfers_enabled'=>false];
  if(!$this->db->query('SELECT pg_try_advisory_lock(741075,23)')->fetchColumn())throw new \RuntimeException('Migracao ou worker em andamento.');
  try{
   // Consume authentic monthly webhook inbox entries only for scoped synthetic card tests.
   // No charge creation, capture, refund or transfer occurs in this branch.
   foreach(FinancialReleasePolicy::monthlyCardWebhookObligations() as $oid){
    if(--$limit<0)break;
    $q=$this->db->prepare("SELECT e.id FROM asaas_webhook_eventos e JOIN obrigacoes_mensalidades o ON o.asaas_payment_id=e.asaas_payment_id WHERE o.id=:o AND e.status_processamento IN ('recebido','erro') ORDER BY e.id LIMIT 100");$q->execute(['o'=>$oid]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $eid){$state=(new AsaasWebhookService($this->db))->processarPorId((int)$eid,true);if($state==='processado')$out['webhooks']++;else $out['deferred']++;}
   }
   $rows=$this->db->query("SELECT id,usuario_id,chacara_id,proprietario_id FROM reservas WHERE schema_financeiro=2 AND status_reserva NOT IN ('finalizada','estornada') ORDER BY id")->fetchAll();
   $billing=new ReservationBillingService($this->db,$this->gateway);
   foreach($rows as $row){
    if(!in_array((int)$row['usuario_id'],$registry['users'],true)||!in_array((int)$row['chacara_id'],$registry['properties'],true)||!in_array((int)$row['proprietario_id'],$registry['owners'],true))continue;
    if(--$limit<0)break;$id=(int)$row['id'];$safe=true;
    $q=$this->db->prepare("SELECT id FROM obrigacoes_reserva WHERE reserva_id=:r AND EXISTS(SELECT 1 FROM operacoes_financeiras f WHERE f.tipo='cobranca' AND f.entidade='reserva-obrigacao:'||obrigacoes_reserva.id) ORDER BY id");$q->execute(['r'=>$id]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $oid){try{$result=$billing->reconcile((int)$oid);$out['reconciled']++;if(in_array($result['estado']??'',['conciliacao_manual','aguardando_webhook'],true))$safe=false;}catch(Throwable){$safe=false;$out['deferred']++;}}
    $q=$this->db->prepare("SELECT e.id FROM asaas_webhook_eventos e JOIN obrigacoes_reserva o ON o.asaas_payment_id=e.asaas_payment_id WHERE o.reserva_id=:r AND e.status_processamento IN ('recebido','erro') ORDER BY e.id LIMIT 100");$q->execute(['r'=>$id]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $eid){$state=(new AsaasWebhookService($this->db))->processarPorId((int)$eid,true);if($state==='processado')$out['webhooks']++;else{$safe=false;$out['deferred']++;}}
    // Never expire/cancel while gateway reconciliation or delivery is unresolved.
    if($safe){
     $this->db->beginTransaction();try{$r=ReservationLock::acquire($this->db,$id);if($r['status_reserva']==='aguardando_pagamento'&&new \DateTimeImmutable($r['expira_em'])<=new \DateTimeImmutable($r['_agora'])){(new ReservaStatusService($this->db))->transicionar($id,'expirada',['motivo'=>'Checkout Sandbox vencido apos conciliacao','origem'=>'sistema','responsavel_tipo'=>'sistema']);$out['expired']++;}$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
     if((new InstallmentDelinquencyService($this->db))->cancelIfDue($id))$out['delinquent']++;
    }
    $q=$this->db->prepare("SELECT * FROM tarefas_financeiras_reserva WHERE reserva_id=:r AND tipo IN ('emitir_parcela','cancelar_cobranca') AND estado IN ('pendente','erro','processando') AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em<=clock_timestamp()) ORDER BY id LIMIT 50");$q->execute(['r'=>$id]);
    foreach($q->fetchAll() as $job){
     try{
      $this->db->prepare("UPDATE tarefas_financeiras_reserva SET estado='processando',tentativas=tentativas+1,atualizado_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$job['id']]);
      if($job['tipo']==='emitir_parcela'){$billing->issueScheduled((int)$job['obrigacao_id']);$state='concluida';}
      else{$result=(new ReservationSettlementService($this->db,$this->gateway))->cancelPayment((int)$job['obrigacao_id']);$state=($result['estado']??'')==='concluido'?'concluida':'pendente';}
      $this->db->prepare("UPDATE tarefas_financeiras_reserva SET estado=:s,erro_codigo=NULL,proxima_tentativa_em=clock_timestamp()+INTERVAL '60 seconds',atualizado_em=clock_timestamp() WHERE id=:id")->execute(['s'=>$state,'id'=>$job['id']]);$out['jobs']++;
     }catch(Throwable){$this->db->prepare("UPDATE tarefas_financeiras_reserva SET estado='erro',erro_codigo='VERIFICACAO_SANDBOX_PENDENTE',proxima_tentativa_em=clock_timestamp()+INTERVAL '60 seconds' WHERE id=:id")->execute(['id'=>$job['id']]);$out['deferred']++;}
    }
   }
   return $out;
  }finally{$this->db->query('SELECT pg_advisory_unlock(741075,23)');}
 }
}
