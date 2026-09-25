<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';require __DIR__.'/support/FinancialGatewayFake.php';require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;use App\Services\AsaasCustomerService;use App\Services\FinancialOperationService;use App\Services\ReservationBillingService;use App\Services\ReservationSettlementService;use App\Services\PrecificacaoReservaService;use App\Services\CancelamentoReservaService;use App\Services\ExceptionalReservationCancellationService;
$db=Database::getConnection();$fixture=new FinancialFixture($db);$fake=new FinancialGatewayFake($fixture->tag);$billing=new ReservationBillingService($db,$fake);$settlement=new ReservationSettlementService($db,$fake);$user=$fixture->users['cliente'];$limits=$db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $f,string $m)use($check):void{try{$f();}catch(PDOException $e){throw $e;}catch(RuntimeException|DomainException){$check(true,$m);return;}throw new RuntimeException($m);};
try{
 $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000,entrada_maxima_bps=8000 WHERE id=1');$db->prepare('UPDATE configuracoes_comerciais_imoveis SET aceita_parcelamento=TRUE,entrada_bps=3000 WHERE chacara_id=:c')->execute(['c'=>$fixture->property]);
 $quote=$fixture->quote(180,'entrada_parcelamento','BOLETO');$r=$fixture->consume($quote);$snapshot=$quote['detalhes'];$p=$snapshot['pagamentos'][0];
 // Model a confirmed entry independently of the still-pending policy for boleto checkout duration.
 $customer=(new AsaasCustomerService($fake,$db,$fake->scope))->obter($user);
 $q=$db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,forma_pagamento) VALUES(:r,0,'entrada',:h,:o,:c,:p,:t,:due,'BOLETO') RETURNING id");$q->execute(['r'=>$r,'h'=>$p['hospedagem_centavos'],'o'=>$p['taxa_operacional_centavos'],'c'=>$p['comissao_centavos'],'p'=>$p['proprietario_centavos'],'t'=>$p['total_centavos'],'due'=>$quote['expira_em']]);$entry=(int)$q->fetchColumn();
 $payload=['customer'=>$customer['id'],'billingType'=>'BOLETO','value'=>PrecificacaoReservaService::centavosParaDecimal($p['total_centavos']),'dueDate'=>date('Y-m-d')];$remote=(new FinancialOperationService($fake->scope,$db))->executar('cobranca','reserva-obrigacao:'.$entry,1,$payload,fn()=>[],fn($ref,$p)=>$fake->criarCobranca($p+['externalReference'=>$ref]));
 $check((int)$db->query('SELECT COUNT(*) FROM cronogramas_reserva WHERE reserva_id='.$r)->fetchColumn()===0,'Nenhum cronograma definitivo antes da entrada confirmada');
 $payment=$fake->payments[$remote['id']];$payment['status']='RECEIVED';$payment['clientPaymentDate']=date('Y-m-d');$fake->payments[$remote['id']]=$payment;
 $billing->processPayment([],$payment,'PAYMENT_RECEIVED');$billing->processPayment([],$payment,'PAYMENT_RECEIVED');
 $check((int)$db->query('SELECT COUNT(*) FROM obrigacoes_reserva WHERE reserva_id='.$r)->fetchColumn()===$snapshot['quantidade_pagamentos'],'Entrada cria uma unica vez todas as parcelas congeladas');
 $check($db->query('SELECT status_pagamento FROM reservas WHERE id='.$r)->fetchColumn()==='parcialmente_pago','Entrada confirma reserva sem declarar saldo integral quitado');
 $check((int)$db->query('SELECT COUNT(*) FROM cronogramas_reserva WHERE reserva_id='.$r)->fetchColumn()===1,'Repeticao nao recria referencia mensal');
 $reject(fn()=>(new CancelamentoReservaService($db))->cancelarUsuario($r,$user),'Hospede nao cancela voluntariamente depois da entrada');
 $reject(fn()=>(new CancelamentoReservaService($db))->cancelarUsuario($r,$fixture->users['proprietario']),'Proprietario nao cancela contrato do hospede');
 $out=$settlement->payout($entry);$check($out['estado']==='concluido'&&reset($fake->transfers)['value']==='54.00','Entrada liquidada gera repasse imediato de H proporcional menos C, antes de L');
 $check($db->query('SELECT status_repasse FROM reservas WHERE id='.$r)->fetchColumn()==='parcialmente_concluido','Repasse da entrada nao encerra projecao de parcelas futuras');
 $q=$db->query('SELECT * FROM obrigacoes_reserva WHERE reserva_id='.$r.' AND numero>0 ORDER BY numero');$rows=$q->fetchAll();
 foreach($rows as $i=>$o){$s=$snapshot['pagamentos'][$i+1];$check($o['hospedagem_centavos']===$s['hospedagem_centavos']&&$o['taxa_operacional_centavos']===$s['taxa_operacional_centavos']&&$o['comissao_centavos']===$s['comissao_centavos'],'Parcela preserva hospedagem, taxa e comissao do checkout');$one=$billing->issueScheduled((int)$o['id']);$two=$billing->issueScheduled((int)$o['id']);$check($one['asaas_payment_id']===$two['asaas_payment_id'],'Emissao de boleto por obrigacao e idempotente');$pay=$fake->payments[$one['asaas_payment_id']];$check($pay['interest']['value']===0&&$pay['fine']['value']===0,'Boleto nao adiciona juros ou multa');$pay['status']='RECEIVED';$billing->processPayment([],$pay,'PAYMENT_RECEIVED');}
 $check($db->query('SELECT status_pagamento FROM reservas WHERE id='.$r)->fetchColumn()==='pago','Somente quitacao de todas as obrigacoes marca contrato pago');
 $reject(fn()=>(new ExceptionalReservationCancellationService($db))->cancel($r,$fixture->users['admin'],'Motivo administrativo',''),'Admin precisa explicitar politica financeira');
 (new ExceptionalReservationCancellationService($db))->cancel($r,$fixture->users['admin'],'Impossibilidade da hospedagem','Conciliar administrativamente os valores; nenhuma devolucao automatica.');
 $check($db->query('SELECT status_reserva FROM reservas WHERE id='.$r)->fetchColumn()==='cancelada','Excecao administrativa documentada cancela reserva');
 $check((int)$db->query('SELECT COUNT(*) FROM reembolsos_reservas WHERE reserva_id='.$r)->fetchColumn()===0,'Excecao administrativa nao inventa estorno');
 $check((int)$db->query('SELECT recebido_centavos FROM cancelamentos_administrativos_reserva WHERE reserva_id='.$r)->fetchColumn()===$snapshot['total_centavos'],'Auditoria administrativa preserva total efetivamente recebido');
}finally{$db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max WHERE id=1')->execute(['min'=>$limits['entrada_minima_bps'],'max'=>$limits['entrada_maxima_bps']]);$fixture->close();}
echo "Fase 8: cronograma, rateio, boleto e excecao administrativa verificados localmente. Entrada e recorrencia possuem suite complementar propria.\n";
