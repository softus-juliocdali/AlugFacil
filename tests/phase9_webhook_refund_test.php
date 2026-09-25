<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';require __DIR__.'/support/FinancialFixture.php';require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;use App\Services\ReservationBillingService;use App\Services\ReservationSettlementService;use App\Services\AsaasWebhookService;use App\Services\CancelamentoReservaService;
$db=Database::getConnection();$fixture=new FinancialFixture($db);$fake=new FinancialGatewayFake($fixture->tag);$billing=new ReservationBillingService($db,$fake);$settlement=new ReservationSettlementService($db,$fake);$worker=new AsaasWebhookService($db);
$check=static function(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo '[OK] '.$name.PHP_EOL;};
$server=null;$prefix='evt_'.$fixture->tag.'_';$sequence=0;
try{
 $timeout=new ReflectionMethod(App\Services\AsaasHttpClient::class,'requestTimeout');$check($timeout->invoke(null,['creditCardToken'=>'synthetic'])>=60,'Captura por token respeita timeout minimo oficial de 60s');
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Porta local indisponivel.');$address=stream_socket_get_name($socket,false);fclose($socket);
 $server=proc_open([PHP_BINARY,'-S',$address,'-t',APP_ROOT.'/public'],[0=>['pipe','r'],1=>['file','NUL','w'],2=>['file','NUL','w']],$pipes,APP_ROOT);if(!is_resource($server))throw new RuntimeException('Servidor local indisponivel.');fclose($pipes[0]);
 for($i=0;$i<40;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($ready){fclose($ready);break;}usleep(50000);}
 $token=(string)getenv('ASAAS_WEBHOOK_TOKEN');$check(strlen($token)>=32,'Token exclusivo de webhook configurado sem exposicao');
 $http=static function(string $body,string $method='POST',?string $auth=null)use($address,$token):array{$ch=curl_init('http://'.$address.'/webhook_asaas.php');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Content-Type: application/json','asaas-access-token: '.($auth??$token)],CURLOPT_POSTFIELDS=>$body,CURLOPT_TIMEOUT=>5]);$out=curl_exec($ch);return ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>$out];};
 $check($http('{}','GET')['http']===405,'Handler real recusa GET');
 $check($http('{}','POST','')['http']===401,'Handler real recusa token ausente');
 $check($http('{}','POST','wrong')['http']===401,'Handler real recusa token incorreto');
 $check($http('{')['http']===400,'JSON invalido recusado');$check($http('[]')['http']===422,'Lista raiz recusada');
 $check($http('{"id":[],"event":"PAYMENT_RECEIVED"}')['http']===422,'ID malformado recusado sem warning');
 $check($http(json_encode(['id'=>$prefix.'bad','event'=>'PAYMENT_RECEIVED','payment'=>[]]))['http']===422,'Pagamento incompleto recusado');
 $check($http(json_encode(['id'=>$prefix.'bad','event'=>'TRANSFER_DONE']))['http']===422,'Transferencia incompleta recusada');
 $apis=require APP_ROOT.'/app/config/apis.php';$check($http(str_repeat('x',(int)$apis['asaas']['webhook_max_body_bytes']+1))['http']===413,'Corpo acima do limite recusado');
 $queue=static function(string $type,array $resource,string $kind='payment')use(&$sequence,$prefix,$http,$db,$check):array{$p=['id'=>$prefix.'&'.++$sequence,'event'=>$type,$kind=>$resource];$body=json_encode($p,JSON_THROW_ON_ERROR);$check($http($body)['http']===200,'Evento '.$type.' com ID composto persistido via HTTP');$q=$db->prepare('SELECT * FROM asaas_webhook_eventos WHERE asaas_event_id=:e');$q->execute(['e'=>$p['id']]);return [$q->fetch(),$p,$body];};
 $rid=$fixture->consume($fixture->quote());$o=$billing->issue($rid,$fixture->users['cliente']);$p=$fake->payments[$o['asaas_payment_id']]+['paymentDate'=>date('Y-m-d')];$p['status']='RECEIVED';
 $p['creditCard']=['creditCardToken'=>'synthetic_secret_marker','number'=>'synthetic_pan_marker'];$p['creditCardHolderInfo']=['name'=>'private_holder_marker'];$p['remoteIp']='127.0.0.1';
 [$row,$payload,$body]=$queue('PAYMENT_RECEIVED',$p);
 $check(!str_contains($row['payload'],'synthetic_secret_marker')&&!str_contains($row['payload'],'private_holder_marker')&&!str_contains($row['payload'],'remoteIp'),'Segredos removidos antes da persistencia');
 $check($worker->processarPorId((int)$row['id'])==='processado','Worker confirma pagamento persistido pelo handler');
 $check($http($body)['http']===200&&$worker->processarPorId((int)$row['id'])==='ignorado','Entrega repetida e worker repetido idempotentes');
 $p['status']='CONFIRMED';[$earlier]=$queue('PAYMENT_CONFIRMED',$p);$worker->processarPorId((int)$earlier['id']);$p['status']='PENDING';[$old]=$queue('PAYMENT_UPDATED',$p);$worker->processarPorId((int)$old['id']);
 $check($db->query('SELECT estado FROM obrigacoes_reserva WHERE id='.(int)$o['id'])->fetchColumn()==='paga','Eventos fora de ordem nao desfazem pagamento');
 $check((int)$db->query('SELECT COUNT(*) FROM pagamentos WHERE reserva_id='.$rid)->fetchColumn()===1,'Fora de ordem nao duplica registro financeiro');
 $payload['payment']['value']=999;$check($http(json_encode($payload))['http']===200,'Mesmo ID divergente recebido sem reenvio infinito');
 $check($worker->processarPorId((int)$row['id'])==='ignorado','Mesmo ID divergente isolado para revisao');
 [$unknown]=$queue('PAYMENT_FUTURE_UNKNOWN',$p);$check($worker->processarPorId((int)$unknown['id'])==='ignorado','Evento novo desconhecido ignorado com seguranca');
 $cancel=(new CancelamentoReservaService($db))->cancelarUsuario($rid,$fixture->users['cliente']);$settlement->refund($cancel['reembolso_id']);
 $item=$fake->payments[$o['asaas_payment_id']]['refunds'][0];$item['status']='CANCELLED';$fake->refundHistory=[$item];$fake->payments[$o['asaas_payment_id']]['refunds']=[];
 $fake->refundPageSize=1;$unrelated=$item;$unrelated['description']='Unrelated';$fake->refundHistory=[$unrelated,$item];
 $check($settlement->refund($cancel['reembolso_id'])['estado']==='falhou','Historico dedicado paginado recupera CANCELLED ausente na cobranca');
 $check($settlement->refund($cancel['reembolso_id'])['estado']==='falhou'&&$fake->refundPosts===1,'Estorno cancelado nao e reenviado');
 $q=$db->query('SELECT status_pagamento,status_reserva FROM reservas WHERE id='.$rid);$projection=$q->fetch();$check($projection['status_pagamento']==='pago'&&$projection['status_reserva']==='cancelada','Refund cancelado encerra projecao processando sem reativar reserva');
 $item['status']='PENDING';$p['refunds']=[$item];$check($settlement->reconcileRefund($p)['estado']==='falhou','Webhook antigo nao reabre estorno cancelado');
 $q=$db->prepare('SELECT estado FROM itens_estorno_asaas WHERE asaas_payment_id=:p AND descricao=:d');$q->execute(['p'=>$p['id'],'d'=>$item['description']]);$check($q->fetchColumn()==='CANCELLED','Ledger conserva estado terminal perante evento antigo');
 $fake->refundHistory=null;
 $near=$fixture->consume($fixture->quote(1));$pay=$billing->issue($near,$fixture->users['cliente']);$tpay=$fake->payments[$pay['asaas_payment_id']];$tpay['status']='RECEIVED';$tpay['paymentDate']=date('Y-m-d');$billing->processPayment([],$tpay,'PAYMENT_RECEIVED');
 $settlement->payout((int)$pay['id']);$transfer=reset($fake->transfers);
 // Seed an intermediate projection only in the isolated fixture to exercise delayed remote completion.
 $db->prepare("UPDATE repasses_obrigacoes SET estado='processando',status_asaas='PENDING' WHERE obrigacao_id=:o")->execute(['o'=>$pay['id']]);
 [$done]=$queue('TRANSFER_DONE',$transfer,'transfer');$check($worker->processarPorId((int)$done['id'])==='processado','Webhook de transferencia conclui repasse correspondente');
 $transfer['status']='PENDING';[$pending]=$queue('TRANSFER_PENDING',$transfer,'transfer');$check($worker->processarPorId((int)$pending['id'])==='processado','Transferencia antiga consumida sem regressao');
 $check($db->query('SELECT estado FROM repasses_obrigacoes WHERE obrigacao_id='.(int)$pay['id'])->fetchColumn()==='concluido'&&$fake->transferPosts===1,'Conclusao de transferencia preservada sem POST');
 $transfer['status']='CANCELLED';[$conflict]=$queue('TRANSFER_CANCELLED',$transfer,'transfer');$check($worker->processarPorId((int)$conflict['id'])==='divergente','Estados terminais contraditorios exigem revisao');
 $transfer['walletId']='wrong';[$bad]=$queue('TRANSFER_DONE',$transfer,'transfer');$check($worker->processarPorId((int)$bad['id'])==='erro','Destino divergente impede atualizar repasse');
}finally{
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 $db->prepare('DELETE FROM asaas_webhook_eventos WHERE asaas_event_id LIKE :p')->execute(['p'=>$prefix.'%']);$fixture->close();
}
echo "Webhook HTTP local e reconciliacao de refunds; nenhuma chamada externa.\n";
