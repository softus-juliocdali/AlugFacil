<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';require __DIR__.'/support/FinancialGatewayFake.php';require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;use App\Services\ReservationBillingService;use App\Services\ReservationCardVault;use App\Services\FinancialPayloadFilter;
$db=Database::getConnection();$f=new FinancialFixture($db);$fake=new FinancialGatewayFake($f->tag);$billing=new ReservationBillingService($db,$fake);$user=$f->users['cliente'];$limits=$db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $fn,string $m)use($check):void{try{$fn();}catch(PDOException $e){throw $e;}catch(RuntimeException|DomainException){$check(true,$m);return;}throw new RuntimeException($m);};
try{
 $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000,entrada_maxima_bps=8000 WHERE id=1');$db->prepare('UPDATE configuracoes_comerciais_imoveis SET aceita_parcelamento=TRUE,entrada_bps=3000 WHERE chacara_id=:c')->execute(['c'=>$f->property]);
 $id=$f->consume($f->quote(180,'entrada_parcelamento','BOLETO'));
 $one=$billing->issue($id,$user);$two=$billing->issue($id,$user);
 $r=$db->query('SELECT * FROM reservas WHERE id='.$id)->fetch();
 $check($one['asaas_payment_id']===$two['asaas_payment_id']&&$fake->paymentPosts===1,'Entrada boleto emite uma unica cobranca');
 $check(strtotime($r['expira_em'])-strtotime($r['entrada_boleto_emitida_em'])===86400,'Entrada boleto tem um dia corrido sem hold de quinze minutos');
 $check($fake->payments[$one['asaas_payment_id']]['dueDate']===date('Y-m-d',strtotime($r['expira_em'])),'Vencimento enviado ao gateway corresponde ao prazo da entrada');
 $check((int)$db->query('SELECT COUNT(*) FROM cronogramas_reserva WHERE reserva_id='.$id)->fetchColumn()===0,'Emitir boleto nao cria cronograma definitivo');
 $reject(fn()=>$f->quote(180),'Entrada aguardando bloqueia as datas');
 $p=$fake->payments[$one['asaas_payment_id']];$p['status']='RECEIVED';$p['clientPaymentDate']=date('Y-m-d');$fake->payments[$p['id']]=$p;
 $billing->reconcile((int)$one['id']);$billing->reconcile((int)$one['id']);
 $check((int)$db->query('SELECT COUNT(*) FROM cronogramas_reserva WHERE reserva_id='.$id)->fetchColumn()===1,'Conciliacao repetida da entrada ativa um unico cronograma');
 $id2=$f->consume($f->quote(200,'entrada_parcelamento','CREDIT_CARD'));
 $reject(fn()=>$billing->issue($id2,$user),'Cartao recorrente exige aceite');
 $entry=$billing->issue($id2,$user,true,'127.0.0.1');$p=$fake->payments[$entry['asaas_payment_id']];$p['status']='RECEIVED';$p['creditCard']=['creditCardToken'=>'synthetic-secret-token'];$p['clientPaymentDate']=date('Y-m-d');$fake->payments[$p['id']]=$p;
 $billing->reconcile((int)$entry['id']);$billing->reconcile((int)$entry['id']);
 $a=$db->query('SELECT * FROM autorizacoes_pagamento_reserva WHERE reserva_id='.$id2)->fetch();
 $check($a['estado']==='ativa'&&!str_contains($a['segredo_cifrado'],'synthetic-secret-token'),'Token de cartao permanece cifrado');
 $vault=new ReservationCardVault($db);$check($vault->credentials($id2,$fake->scope,$p['customer'])['creditCardToken']==='synthetic-secret-token','Cofre recupera token para mesmo cliente e conta');
 $reject(fn()=>$vault->credentials($id2,'different-scope',$p['customer']),'Cofre impede reutilizacao em outra conta');
 $part=(int)$db->query('SELECT id FROM obrigacoes_reserva WHERE reserva_id='.$id2.' AND numero=1')->fetchColumn();
 $reject(fn()=>$billing->issueScheduled($part),'Executor nao antecipa captura de cartao');
 $check(!str_contains(json_encode(FinancialPayloadFilter::sanitize(['payment'=>$p,'remoteIp'=>'127.0.0.1'])),'synthetic-secret-token'),'Filtro remove token e objetos de cartao antes de persistir webhook');
 $q=$db->prepare("SELECT COUNT(*) FROM operacoes_financeiras WHERE conta_gateway=:scope AND (payload::text LIKE :token OR resultado::text LIKE :token)");$q->execute(['scope'=>$fake->scope,'token'=>'%synthetic-secret-token%']);$check((int)$q->fetchColumn()===0,'Intencoes e resultados nao persistem token');
 // A simulated gateway payment date one month ago makes the first card installment due today.
 // This local fixture tests the executor without changing the database/application clock.
 $id3=$f->consume($f->quote(240,'entrada_parcelamento','CREDIT_CARD'));$entry3=$billing->issue($id3,$user,true,'127.0.0.1');
 $p3=$fake->payments[$entry3['asaas_payment_id']];$p3['status']='RECEIVED';$p3['creditCard']=['creditCardToken'=>'synthetic-scheduled-token'];$p3['clientPaymentDate']=(new DateTimeImmutable('today'))->modify('-1 month')->format('Y-m-d');$fake->payments[$p3['id']]=$p3;
 $billing->reconcile((int)$entry3['id']);$part3=(int)$db->query('SELECT id FROM obrigacoes_reserva WHERE reserva_id='.$id3.' AND numero=1')->fetchColumn();$posts=$fake->paymentPosts;
 $scheduled=$billing->issueScheduled($part3);$again=$billing->issueScheduled($part3);
 $check($scheduled['asaas_payment_id']===$again['asaas_payment_id']&&$fake->paymentPosts===$posts+1,'Parcela de cartao vencida envia um unico POST na reexecucao');
 $check($fake->payments[$scheduled['asaas_payment_id']]['creditCardToken']==='synthetic-scheduled-token','Parcela usa token somente no payload efemero do gateway');
 $q->execute(['scope'=>$fake->scope,'token'=>'%synthetic-scheduled-token%']);$check((int)$q->fetchColumn()===0,'Parcela tokenizada nao grava segredo na intencao ou resultado');
 // Expired synthetic entry: seed its original issue time, never change an issued deadline.
 $expiredQuote=$f->quote(280,'entrada_parcelamento','BOLETO');$expired=$f->consume($expiredQuote);$p0=$expiredQuote['detalhes']['pagamentos'][0];
 $db->prepare("WITH t AS (SELECT clock_timestamp()-INTERVAL '2 days' AS issued) UPDATE reservas SET entrada_boleto_emitida_em=t.issued,expira_em=t.issued+INTERVAL '1 day' FROM t WHERE id=:r")->execute(['r'=>$expired]);
 $q=$db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,forma_pagamento) SELECT :r,0,'entrada',:h,:o,:c,:p,:t,expira_em,'BOLETO' FROM reservas WHERE id=:r RETURNING id");$q->execute(['r'=>$expired,'h'=>$p0['hospedagem_centavos'],'o'=>$p0['taxa_operacional_centavos'],'c'=>$p0['comissao_centavos'],'p'=>$p0['proprietario_centavos'],'t'=>$p0['total_centavos']]);$expiredEntry=(int)$q->fetchColumn();
 $customer=reset($fake->customers)['id'];$payload=['customer'=>$customer,'billingType'=>'BOLETO','value'=>\App\Services\PrecificacaoReservaService::centavosParaDecimal($p0['total_centavos']),'dueDate'=>date('Y-m-d')];
 $remote=(new \App\Services\FinancialOperationService($fake->scope,$db))->executar('cobranca','reserva-obrigacao:'.$expiredEntry,1,$payload,fn()=>[],fn($ref,$data)=>$fake->criarCobranca($data+['externalReference'=>$ref]));
 (new \App\Services\ReservaStatusService($db))->expirarVencidas(100,$f->property);
 $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$expired)->fetchColumn()==='expirada','Entrada boleto nao paga encerra automaticamente no prazo proprio');
 $p=$fake->payments[$remote['id']];$p['status']='RECEIVED';$billing->processPayment([],$p,'PAYMENT_RECEIVED');
 $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$expired)->fetchColumn()==='expirada','Pagamento tardio de boleto nao reativa reserva');
 $billing->processPayment([],$p,'PAYMENT_RECEIVED');
 $check($db->query('SELECT estado FROM obrigacoes_reserva WHERE id='.$expiredEntry)->fetchColumn()==='conciliacao_manual','Confirmacao tardia duplicada preserva conciliacao manual');
 $check((int)$db->query('SELECT COUNT(*) FROM cronogramas_reserva WHERE reserva_id='.$expired)->fetchColumn()===0,'Entrada tardia nao cria cronograma');
 $check(!empty($f->quote(280)['id']),'Datas liberadas depois de entrada boleto expirada');
 $unknown=$f->consume($f->quote(300,'entrada_parcelamento','BOLETO'));
 $fake->afterPayment=static function():void{throw new \App\Services\AsaasTimeoutException('Synthetic lost response');};$posts=$fake->paymentPosts;
 $reject(fn()=>$billing->issue($unknown,$user),'Resposta perdida da entrada exige conciliacao');$fake->afterPayment=null;
 $ob=(int)$db->query('SELECT id FROM obrigacoes_reserva WHERE reserva_id='.$unknown.' AND numero=0')->fetchColumn();$billing->reconcile($ob);$again=$billing->issue($unknown,$user);
 $check($fake->paymentPosts===$posts+1&&!empty($again['asaas_payment_id']),'GET recupera entrada de resultado desconhecido sem segundo POST');
 $q=$db->prepare("SELECT estado FROM operacoes_financeiras WHERE entidade=:e AND conta_gateway=:scope");$q->execute(['e'=>'reserva-obrigacao:'.$ob,'scope'=>$fake->scope]);$check($q->fetchColumn()==='concluida','Conciliacao fecha a intencao desconhecida com identidade comprovada');
}finally{$db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max WHERE id=1')->execute(['min'=>$limits['entrada_minima_bps'],'max'=>$limits['entrada_maxima_bps']]);$f->close();}
