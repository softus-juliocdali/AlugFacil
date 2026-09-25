<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use DateTimeImmutable;use PDO;use RuntimeException;use Throwable;

/** Root-account collection followed by one transfer per obligation. No parallel split/escrow path. */
final class ReservationSettlementService
{
 public function __construct(private ?PDO $db=null,private ?object $client=null){$this->db??=Database::getConnection();}
 public function payout(int $obligation):array
 {
  $client=$this->client??=new AsaasHttpClient();$scope=$client->accountScope();$o=$this->obligation($obligation);$property=$o['chacara_id'];
  if($this->db->inTransaction())throw new RuntimeException('Repasse exige transacao independente.');
  $this->db->prepare('SELECT pg_advisory_lock(741073,hashtext(:s))')->execute(['s'=>$scope]);
  $this->db->prepare('SELECT pg_advisory_lock(:c)')->execute(['c'=>$property]);
  try{
   $this->db->beginTransaction();$r=ReservationLock::acquire($this->db,(int)$o['reserva_id']);$o=$this->obligation($obligation);
   $q=$this->db->prepare('SELECT * FROM repasses_obrigacoes WHERE obrigacao_id=:o FOR UPDATE');$q->execute(['o'=>$obligation]);$existing=$q->fetch();
   if($existing&&$existing['estado']==='concluido'){$this->db->commit();return ['estado'=>'concluido'];}
   $earned=$r['modalidade']==='entrada_parcelamento'&&$r['status_reserva']==='cancelada'&&$r['tipo_cancelamento']==='inadimplencia';
   if((!$earned&&!in_array($r['status_reserva'],['confirmada','em_andamento','finalizada'],true))||$o['estado']!=='paga'||$o['pago_centavos']!==$o['total_centavos']||$o['liquidado_em']===null||(!$earned&&$r['divergencia_pagamento'])||($r['modalidade']==='integral'&&!FinancialDeadlinePolicy::mayTransfer(new DateTimeImmutable($r['_agora']),new DateTimeImmutable($r['repasse_liberavel_em']))))throw new RuntimeException('Aguardardando elegibilidade e liquidacao do pagamento.');
   if(!AsaasSubcontaService::isFinanciallyEnabled($this->db,(int)$r['proprietario_id']))throw new RuntimeException('Recebedor sem habilitacao financeira.');
   $q=$this->db->prepare("SELECT asaas_wallet_id FROM asaas_subcontas WHERE proprietario_id=:p AND ambiente='sandbox'");$q->execute(['p'=>$r['proprietario_id']]);$wallet=(string)$q->fetchColumn();
   if($existing&&$existing['wallet_id']!==$wallet)throw new RuntimeException('Wallet alterada; conciliacao obrigatoria.');
   $this->db->prepare('INSERT INTO repasses_obrigacoes(obrigacao_id,valor_centavos,wallet_id) VALUES(:o,:v,:w) ON CONFLICT(obrigacao_id) DO NOTHING')->execute(['o'=>$obligation,'v'=>$o['proprietario_centavos'],'w'=>$wallet]);$this->db->commit();
   if($o['proprietario_centavos']===0){$this->updatePayout($obligation,'concluido',null,null,'ZERO');return ['estado'=>'concluido'];}
   $payload=['walletId'=>$wallet,'value'=>PrecificacaoReservaService::centavosParaDecimal($o['proprietario_centavos'])];
   $out=(new FinancialOperationService($scope,$this->db))->executar('transferencia','obrigacao:'.$obligation,1,$payload,
    function(string $ref,array $p)use($client):array{$found=[];$offset=0;do{$page=$client->listarTransferencias(['externalReference'=>$ref,'limit'=>100,'offset'=>$offset]);foreach($page['data']??[] as $x){if(($x['externalReference']??'')!==$ref)continue;$this->assertTransfer($x,$p);$found[]=$x;}$offset+=100;}while(!empty($page['hasMore']));return $found;},
    fn(string $ref,array $p):array=>$client->criarTransferencia($p+['externalReference'=>$ref]),
    function(string $ref,array $p)use($client):void{$balance=$client->consultarSaldo();if(PrecificacaoReservaService::decimalParaCentavos((string)($balance['balance']??''))<PrecificacaoReservaService::decimalParaCentavos($p['value']))throw new RuntimeException('Saldo liquidado insuficiente.');});
   $remote=$client->consultarTransferencia($out['id']);$this->assertTransfer($remote,$payload);
   $state=match($remote['status']??''){'DONE'=>'concluido','PENDING','BANK_PROCESSING'=>'processando','FAILED'=>'falhou','CANCELLED'=>'cancelado',default=>'manual'};
   $this->updatePayout($obligation,$state,$out['id'],$out['_operation_id'],(string)($remote['status']??''));return ['estado'=>$state];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  finally{$this->db->prepare('SELECT pg_advisory_unlock(:c)')->execute(['c'=>$property]);$this->db->prepare('SELECT pg_advisory_unlock(741073,hashtext(:s))')->execute(['s'=>$scope]);}
 }
 public function cancelPayment(int $id):array
 {
  $o=$this->obligation($id);$client=$this->client??=new AsaasHttpClient();
  if(!$o['asaas_payment_id']){ $q=$this->db->prepare("SELECT estado FROM operacoes_financeiras WHERE tipo='cobranca' AND entidade=:e");$q->execute(['e'=>'reserva-obrigacao:'.$id]);$state=$q->fetchColumn();if($state!==false)throw new RuntimeException('Intencao de cobranca ainda precisa de conciliacao.');return ['estado'=>'concluido'];}
  if(!in_array($o['status_reserva'],ReservaStatusService::FINAIS,true)&&$o['estado']!=='cancelada')throw new RuntimeException('Cancelamento de instrumento sem cancelamento local.');
  $out=(new FinancialOperationService($client->accountScope(),$this->db))->executar('cancelar_cobranca','obrigacao:'.$id,1,['paymentId'=>$o['asaas_payment_id']],
   function(string $ref,array $p)use($client):array{$x=$client->consultarCobranca($p['paymentId']);return !empty($x['deleted'])?[$x]:[];},
   fn(string $ref,array $p):array=>$client->cancelarCobranca($p['paymentId'])+['id'=>$p['paymentId']]);
  return ['estado'=>!empty($out['deleted'])?'concluido':'manual'];
 }
 public function reconcileTransfer(array $transfer):array
 {
  if(!$this->db->inTransaction())throw new RuntimeException('Conciliacao de transferencia exige transacao.');
  $q=$this->db->prepare('SELECT o.reserva_id FROM repasses_obrigacoes p JOIN obrigacoes_reserva o ON o.id=p.obrigacao_id WHERE p.asaas_transfer_id=:id');$q->execute(['id'=>$transfer['id']??'']);$reservation=$q->fetchColumn();
  if(!$reservation)return ['status'=>'divergente','erro'=>'Transferencia sem repasse correspondente.'];
  ReservationLock::acquire($this->db,(int)$reservation);
  $q=$this->db->prepare('SELECT p.*,f.referencia FROM repasses_obrigacoes p JOIN operacoes_financeiras f ON f.id=p.operacao_id WHERE p.asaas_transfer_id=:id FOR UPDATE OF p');$q->execute(['id'=>$transfer['id']??'']);$p=$q->fetch();
  if(!$p)return ['status'=>'divergente','erro'=>'Transferencia sem repasse correspondente.'];
  $this->assertTransfer($transfer,['walletId'=>$p['wallet_id'],'value'=>PrecificacaoReservaService::centavosParaDecimal($p['valor_centavos'])]);
  if(($transfer['externalReference']??'')!==$p['referencia'])throw new RuntimeException('Referencia da transferencia divergente.');
  $state=match($transfer['status']??''){'DONE'=>'concluido','PENDING','BANK_PROCESSING'=>'processando','FAILED'=>'falhou','CANCELLED'=>'cancelado',default=>'manual'};
  if(in_array($p['estado'],['concluido','cancelado','falhou'],true)){
   if(in_array($state,['concluido','cancelado','falhou'],true)&&$state!==$p['estado'])return ['status'=>'divergente','erro'=>null];
   $this->updatePayout((int)$p['obrigacao_id'],$p['estado'],$p['asaas_transfer_id'],(int)$p['operacao_id'],(string)$p['status_asaas']);return ['status'=>'processado','erro'=>null];
  }
  $this->updatePayout((int)$p['obrigacao_id'],$state,$transfer['id'],(int)$p['operacao_id'],(string)($transfer['status']??''));
  return ['status'=>$state==='manual'?'divergente':'processado','erro'=>null];
 }
 public function refund(int $id):array
 {
  $q=$this->db->prepare('SELECT * FROM reembolsos_reservas WHERE id=:id');$q->execute(['id'=>$id]);$f=$q->fetch();if(!$f)throw new RuntimeException('Reembolso nao encontrado.');if(in_array($f['status_local'],['concluido','falhou'],true))return ['estado'=>$f['status_local']];if($f['status_local']==='conciliacao_manual')return $this->reconcileRefundByPayment($f['asaas_payment_id']);
  $client=$this->client??=new AsaasHttpClient();$scope=$client->accountScope();
  $this->db->beginTransaction();try{$r=ReservationLock::acquire($this->db,(int)$f['reserva_id']);if($r['modalidade']!=='integral'||$r['status_reserva']!=='cancelada'||!$f['asaas_payment_id']||$f['valor_reembolso_centavos']!==$r['valor_hospedagem_centavos'])throw new RuntimeException('Reembolso sem politica integral aprovada.');$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  $payload=['paymentId'=>$f['asaas_payment_id'],'value'=>PrecificacaoReservaService::centavosParaDecimal($f['valor_reembolso_centavos']),'description'=>'AF refund '.$id];
  $result=(new FinancialOperationService($scope,$this->db))->executar('refund','reembolso:'.$id,1,$payload,
   function(string $ref,array $p)use($client):array{$x=$this->paymentWithRefundHistory($client->consultarCobranca($p['paymentId']));$matches=$this->refundMatches($x,$p);if(count($matches)>1)throw new RuntimeException('Multiplos estornos com mesma referencia; conciliacao obrigatoria.');return $matches?[$x]:[];},
   fn(string $ref,array $p):array=>$client->estornarCobranca($p['paymentId'],['value'=>$p['value'],'description'=>$p['description']]));
  $this->db->prepare("UPDATE reembolsos_reservas SET operacao_id=:op,status_local='processando',processando_em=COALESCE(processando_em,clock_timestamp()) WHERE id=:id AND status_local<>'concluido'")->execute(['op'=>$result['_operation_id'],'id'=>$id]);
  return $this->reconcileRefundByPayment($f['asaas_payment_id']);
 }
 /** Dedicated history includes cancelled refunds omitted from GET /payments/{id}. No writes remotely. */
 public function paymentWithRefundHistory(array $payment):array
 {
  $client=$this->client??=new AsaasHttpClient();$items=[];$offset=0;
  do{$page=$client->listarEstornosCobranca($payment['id'],['limit'=>100,'offset'=>$offset]);if(!isset($page['data'])||!is_array($page['data']))throw new RuntimeException('Historico de estornos invalido.');$items=array_merge($items,$page['data']);$count=count($page['data']);if(!empty($page['hasMore'])&&$count===0)throw new RuntimeException('Historico de estornos incompleto.');$offset+=$count;}while(!empty($page['hasMore']));
  $payment['refunds']=$items;return $payment;
 }
 public function reconcileRefundByPayment(string $id):array
 {$client=$this->client??=new AsaasHttpClient();return $this->reconcileRefund($this->paymentWithRefundHistory($client->consultarCobranca($id)));}
 public function reconcileRefund(array $payment):array
 {
  $q=$this->db->prepare('SELECT * FROM reembolsos_reservas WHERE asaas_payment_id=:p ORDER BY id');$q->execute(['p'=>$payment['id']??'']);$refunds=$q->fetchAll();if(!$refunds)return ['estado'=>'manual'];
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,(int)$refunds[0]['reserva_id']);$q->execute(['p'=>$payment['id']]);$refunds=$q->fetchAll();$state='processando';
   foreach($payment['refunds']??[] as $item){$value=PrecificacaoReservaService::decimalParaCentavos((string)($item['value']??''));$identity=hash('sha256',json_encode([$item['dateCreated']??null,$value,$item['description']??null],JSON_THROW_ON_ERROR));$this->db->prepare('INSERT INTO itens_estorno_asaas(asaas_payment_id,identidade,valor_centavos,estado,descricao,data_externa,comprovante_url) VALUES(:p,:i,:v,:s,:d,:t,:url) ON CONFLICT(asaas_payment_id,identidade) DO UPDATE SET estado=CASE WHEN itens_estorno_asaas.estado IN (\'DONE\',\'CANCELLED\') THEN itens_estorno_asaas.estado ELSE EXCLUDED.estado END,comprovante_url=EXCLUDED.comprovante_url,observado_em=clock_timestamp()')->execute(['p'=>$payment['id'],'i'=>$identity,'v'=>$value,'s'=>$item['status']??'UNKNOWN','d'=>$item['description']??null,'t'=>$item['dateCreated']??null,'url'=>$item['transactionReceiptUrl']??null]);}
   foreach($refunds as $f){
    if(in_array($f['status_local'],['concluido','falhou'],true)){$state=$f['status_local'];continue;}
    $matches=$this->refundMatches($payment,['description'=>'AF refund '.$f['id'],'value'=>PrecificacaoReservaService::centavosParaDecimal($f['valor_reembolso_centavos'])]);
    if(count($matches)!==1){
     $state=$f['status_local']==='conciliacao_manual'?'manual':'processando';
     if(!empty($payment['refunds'])&&$f['status_local']!=='concluido'){
      $this->db->prepare("UPDATE reembolsos_reservas SET status_local='conciliacao_manual',status_asaas='UNMATCHED_REFUND',ultima_sincronizacao_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$f['id']]);
      if($f['status_local']!=='conciliacao_manual')$this->db->prepare("INSERT INTO historico_reembolsos_reservas(reembolso_id,reserva_id,status_anterior,status_novo,origem,evento) VALUES(:f,:r,:old,'conciliacao_manual','sistema','ESTORNO_SEM_CORRELACAO')")->execute(['f'=>$f['id'],'r'=>$r['id'],'old'=>$f['status_local']]);
      $state='manual';
     }
     continue;
    }
    $remote=$matches[0]['status']??'';$local=match($remote){'DONE'=>'concluido','CANCELLED'=>'falhou','PENDING'=>'processando',default=>'conciliacao_manual'};
    if($f['status_local']==='concluido'){$state='concluido';continue;}
    $this->db->prepare("UPDATE reembolsos_reservas SET status_local=:s,status_asaas=:remote,concluido_em=CASE WHEN :done THEN clock_timestamp() ELSE concluido_em END,ultima_sincronizacao_em=clock_timestamp() WHERE id=:id")->execute(['s'=>$local,'remote'=>$remote,'done'=>$local==='concluido','id'=>$f['id']]);
    if($local!==$f['status_local'])$this->db->prepare("INSERT INTO historico_reembolsos_reservas(reembolso_id,reserva_id,status_anterior,status_novo,origem,evento) VALUES(:f,:r,:old,:new,'sistema','CONCILIACAO_ASAAS')")->execute(['f'=>$f['id'],'r'=>$r['id'],'old'=>$f['status_local'],'new'=>$local]);
    if($local==='concluido'){$money=$f['valor_reembolso_centavos']===$r['valor_total_cliente_centavos']?'estornado':'parcialmente_estornado';$this->db->prepare('UPDATE reservas SET status_pagamento=:s WHERE id=:r')->execute(['s'=>$money,'r'=>$r['id']]);$this->db->prepare("UPDATE obrigacoes_reserva SET estado='estornada' WHERE reserva_id=:r AND asaas_payment_id=:p")->execute(['r'=>$r['id'],'p'=>$payment['id']]);}
    $state=$local==='conciliacao_manual'?'manual':$local;
   }
   $this->db->prepare("UPDATE reservas SET status_pagamento='pago' WHERE id=:r AND status_pagamento='reembolso_processando' AND NOT EXISTS(SELECT 1 FROM reembolsos_reservas f WHERE f.reserva_id=:r AND f.status_local<>'falhou')")->execute(['r'=>$r['id']]);
   if($own)$this->db->commit();return ['estado'=>$state];
  }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function refundMatches(array $payment,array $expected):array{return array_values(array_filter($payment['refunds']??[],static fn(array $x):bool=>($x['description']??'')===$expected['description']&&PrecificacaoReservaService::decimalParaCentavos((string)($x['value']??''))===PrecificacaoReservaService::decimalParaCentavos($expected['value'])));}
 private function obligation(int $id):array{$q=$this->db->prepare('SELECT o.*,r.chacara_id,r.status_reserva FROM obrigacoes_reserva o JOIN reservas r ON r.id=o.reserva_id WHERE o.id=:id');$q->execute(['id'=>$id]);return $q->fetch()?:throw new RuntimeException('Obrigacao nao encontrada.');}
 private function assertTransfer(array $x,array $p):void{if(($x['walletId']??'')!==$p['walletId']||PrecificacaoReservaService::decimalParaCentavos((string)($x['value']??''))!==PrecificacaoReservaService::decimalParaCentavos($p['value']))throw new RuntimeException('Transferencia com valor ou destino divergente.');}
 private function updatePayout(int $id,string $state,?string $transfer,?int $operation,string $remote):void
 {$this->db->prepare('UPDATE repasses_obrigacoes SET estado=:s,asaas_transfer_id=:t,operacao_id=:o,status_asaas=:remote,atualizado_em=clock_timestamp() WHERE obrigacao_id=:id')->execute(['s'=>$state,'t'=>$transfer,'o'=>$operation,'remote'=>$remote,'id'=>$id]);$o=$this->obligation($id);$q=$this->db->prepare('SELECT modalidade FROM reservas WHERE id=:r');$q->execute(['r'=>$o['reserva_id']]);$mode=$q->fetchColumn();
  if($mode==='entrada_parcelamento'){
   $q=$this->db->prepare("SELECT COUNT(*) total,COUNT(*) FILTER(WHERE p.estado='concluido') done,COUNT(*) FILTER(WHERE p.estado='processando') processing,COUNT(*) FILTER(WHERE p.estado IN ('manual','falhou','cancelado')) problem FROM obrigacoes_reserva o LEFT JOIN repasses_obrigacoes p ON p.obrigacao_id=o.id WHERE o.reserva_id=:r");$q->execute(['r'=>$o['reserva_id']]);$sum=$q->fetch();$aggregate=$sum['done']===$sum['total']?'concluido':($sum['problem']>0?'conciliacao_manual':($sum['done']>0?'parcialmente_concluido':($sum['processing']>0?'processando':'aguardando_liberacao')));
   $this->db->prepare('UPDATE repasses_reservas SET status_local=:s,asaas_transfer_id=NULL,atualizado_em=clock_timestamp() WHERE reserva_id=:r')->execute(['s'=>$aggregate,'r'=>$o['reserva_id']]);return;
  }
  $local=match($state){'manual'=>'conciliacao_manual','pendente'=>'aguardando_liberacao',default=>$state};$this->db->prepare('UPDATE repasses_reservas SET status_local=:s,asaas_transfer_id=:t,status_asaas=:remote,atualizado_em=clock_timestamp() WHERE reserva_id=(SELECT reserva_id FROM obrigacoes_reserva WHERE id=:o)')->execute(['s'=>$local,'t'=>$transfer,'remote'=>$remote,'o'=>$id]);}
}
