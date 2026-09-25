<?php

declare(strict_types=1);

require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\AsaasWebhookEvento;
use App\Services\AffiliateCommissionService;
use App\Services\MensalidadeAnuncioService;

$db=Database::getConnection();if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
$ok=0;$fail=0;$check=static function(string$name,bool$condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$tag=(string)random_int(10000000,99999999);$db->beginTransaction();
try{
    $userInsert=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES(:nome,:email,'hash',:tipo,'ativo') RETURNING id");
    $userInsert->execute(['nome'=>'Admin AF4','email'=>'admin.af4.'.$tag.'@test.local','tipo'=>'admin']);$adminId=(int)$userInsert->fetchColumn();
    $userInsert->execute(['nome'=>'Owner AF4','email'=>'owner.af4.'.$tag.'@test.local','tipo'=>'proprietario']);$ownerUserId=(int)$userInsert->fetchColumn();
    $userInsert->execute(['nome'=>'Common AF4','email'=>'common.af4.'.$tag.'@test.local','tipo'=>'proprietario']);$commonUserId=(int)$userInsert->fetchColumn();
    $affiliateInsert=$db->prepare("INSERT INTO afiliados(nome,cpf_cnpj,telefone,email,senha_hash,chave_pix,tipo_chave_pix,status) VALUES('Afiliado AF4',:doc,'11999999999',:email,'hash',:pix,'aleatoria','ativo') RETURNING id");
    $affiliateInsert->execute(['doc'=>'39053344705','email'=>'affiliate.af4.'.$tag.'@test.local','pix'=>'pix-'.$tag]);$affiliateId=(int)$affiliateInsert->fetchColumn();
    $ownerInsert=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status,afiliado_id,afiliado_origem,afiliado_atribuido_em) VALUES(:usuario,:nome,:email,'ativo',CAST(:afiliado AS BIGINT),:origem,CASE WHEN CAST(:afiliado AS BIGINT) IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END) RETURNING id");
    $ownerInsert->execute(['usuario'=>$ownerUserId,'nome'=>'Owner AF4','email'=>'owner.af4.'.$tag.'@test.local','afiliado'=>$affiliateId,'origem'=>'codigo']);$ownerId=(int)$ownerInsert->fetchColumn();
    $ownerInsert->execute(['usuario'=>$commonUserId,'nome'=>'Common AF4','email'=>'common.af4.'.$tag.'@test.local','afiliado'=>null,'origem'=>null]);$commonOwnerId=(int)$ownerInsert->fetchColumn();
    $propertyInsert=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,valor_diaria,cidade,endereco,status,status_aprovacao,status_operacional) VALUES(:owner,:nome,100,'Sao Paulo','Rua Teste','disponivel','aprovada','disponivel') RETURNING id");
    $propertyInsert->execute(['owner'=>$ownerId,'nome'=>'Chacara A '.$tag]);$propertyA=(int)$propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner'=>$ownerId,'nome'=>'Chacara B '.$tag]);$propertyB=(int)$propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner'=>$commonOwnerId,'nome'=>'Chacara Comum '.$tag]);$propertyCommon=(int)$propertyInsert->fetchColumn();
    $monthlyInsert=$db->prepare("INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status,asaas_subscription_id) VALUES(:chacara,:owner,TRUE,:valor,'PENDENTE',:subscription) RETURNING id");
    $monthlyInsert->execute(['chacara'=>$propertyA,'owner'=>$ownerId,'valor'=>10000,'subscription'=>'sub_af4_a_'.$tag]);$monthlyA=(int)$monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara'=>$propertyB,'owner'=>$ownerId,'valor'=>20000,'subscription'=>'sub_af4_b_'.$tag]);$monthlyB=(int)$monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara'=>$propertyCommon,'owner'=>$commonOwnerId,'valor'=>10000,'subscription'=>'sub_af4_c_'.$tag]);$monthlyCommon=(int)$monthlyInsert->fetchColumn();
    $db->prepare('UPDATE afiliados SET percentual_comissao_bps=1000 WHERE id=:id')->execute(['id'=>$affiliateId]);
    $monthlyService=new MensalidadeAnuncioService($db);$finance=new AffiliateCommissionService($db);
    $event=static fn(string$id,string$payment):array=>['asaas_event_id'=>$id,'asaas_payment_id'=>$payment];
    $payment=static fn(string$id,string$subscription,int$value,string$status='RECEIVED',string$confirmed='2026-08-10T14:00:00-03:00'):array=>['id'=>$id,'subscription'=>$subscription,'value'=>number_format($value/100,2,'.',''),'status'=>$status,'confirmedDate'=>$confirmed,'externalReference'=>str_replace('sub_af4_','mensalidade_chacara_',$subscription)];
    $payA=$payment('pay_af4_a_'.$tag,'sub_af4_a_'.$tag,10000);$payB=$payment('pay_af4_b_'.$tag,'sub_af4_b_'.$tag,20000);$payCommon=$payment('pay_af4_c_'.$tag,'sub_af4_c_'.$tag,10000);
    $monthlyService->processarPagamento($event('evt_af4_a_'.$tag,$payA['id']),$payA,'PAYMENT_RECEIVED');
    $check('01 proprietario afiliado gera uma comissao por pagamento',(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE afiliado_id=$affiliateId")->fetchColumn()===1);
    $commissionA=$db->query("SELECT * FROM comissoes_afiliados WHERE asaas_payment_id='".$payA['id']."'")->fetch();
    $check('02 base recebida e snapshot de 10 por cento geram R$ 10',(int)$commissionA['valor_base_centavos']===10000&&(int)$commissionA['percentual_bps']===1000&&(int)$commissionA['valor_comissao_centavos']===1000);
    $monthlyService->processarPagamento($event('evt_af4_a_dup_'.$tag,$payA['id']),$payA,'PAYMENT_RECEIVED');
    $check('03 pagamento processado diretamente duas vezes permanece idempotente',(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE asaas_payment_id='".$payA['id']."'")->fetchColumn()===1);
    $webhookPayload=['id'=>'evt_af4_webhook_duplicate_'.$tag,'event'=>'PAYMENT_RECEIVED','payment'=>$payA];$canonical=json_encode($webhookPayload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$webhookModel=new AsaasWebhookEvento($db);$firstWebhook=$webhookModel->receber($webhookPayload,$canonical);$secondWebhook=$webhookModel->receber($webhookPayload,$canonical);
    $check('03a mesmo webhook duas vezes e persistido uma vez sem duplicar comissao',$firstWebhook['created']===true&&$secondWebhook['created']===false&&(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE asaas_payment_id='".$payA['id']."'")->fetchColumn()===1);
    $db->prepare("UPDATE afiliados SET status='bloqueado' WHERE id=:id")->execute(['id'=>$affiliateId]);
    $monthlyService->processarPagamento($event('evt_af4_b_'.$tag,$payB['id']),$payB,'PAYMENT_CONFIRMED');
    $check('04 afiliado bloqueado preserva comissao do vinculo historico',(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE afiliado_id=$affiliateId")->fetchColumn()===2);
    $monthlyService->processarPagamento($event('evt_af4_common_'.$tag,$payCommon['id']),$payCommon,'PAYMENT_RECEIVED');
    $check('05 proprietario sem afiliado nao gera comissao',(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados c JOIN mensalidades_anuncios m ON m.id=c.mensalidade_id WHERE m.proprietario_id=$commonOwnerId")->fetchColumn()===0);
    $open=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-10T14:01:00-03:00'));
    $before=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-17T13:59:59-03:00'));
    $exact=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-17T14:00:00-03:00'));
    $after=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-18T14:00:00-03:00'));
    $check('06 comissao nova e 6 dias 23 horas permanecem em aberto',$open['em_aberto_centavos']===3000&&$before['em_aberto_centavos']===3000&&$before['saldo_disponivel_centavos']===0);
    $check('07 exatamente sete dias e depois ficam disponiveis',$exact['saldo_disponivel_centavos']===3000&&$after['saldo_disponivel_centavos']===3000);
    $manual=$finance->registerManualPayment($affiliateId,2500,'2026-08-17','PIX-AF4','Parcial FIFO',$adminId,new DateTimeImmutable('2026-08-17T14:00:00-03:00'));
    $allocations=$db->query('SELECT a.valor_centavos,c.asaas_payment_id FROM alocacoes_pagamentos_afiliados a JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id WHERE a.pagamento_afiliado_id='.(int)$manual['id'].' ORDER BY c.confirmado_em,c.id')->fetchAll();
    $check('08 pagamento parcial usa FIFO e suporta comissao parcial',count($allocations)===2&&(int)$allocations[0]['valor_centavos']===1000&&(int)$allocations[1]['valor_centavos']===1500);
    $afterPayment=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-17T14:00:01-03:00'));
    $check('09 pagamento parcial registra R$ 25 e deixa R$ 5 disponiveis',$afterPayment['total_pago_centavos']===2500&&$afterPayment['saldo_disponivel_centavos']===500);
    foreach([600,0,-100]as$invalid){$rejected=false;try{$finance->registerManualPayment($affiliateId,$invalid,'2026-08-17',null,null,$adminId,new DateTimeImmutable('2026-08-17T14:00:02-03:00'));}catch(RuntimeException){$rejected=true;}$check('10 rejeita pagamento invalido '.$invalid,$rejected);}
    $refund=$payA;$refund['status']='REFUNDED';$monthlyService->processarPagamento($event('evt_af4_refund_'.$tag,$payA['id']),$refund,'PAYMENT_REFUNDED');
    $paidReversed=$db->query("SELECT status,estornada_em FROM comissoes_afiliados WHERE asaas_payment_id='".$payA['id']."'")->fetch();
    $check('11 estorno de comissao paga preserva PAGA e cria ajuste negativo',$paidReversed['status']==='PAGA'&&$paidReversed['estornada_em']!==null&&(int)$db->query("SELECT valor_centavos FROM ajustes_afiliados WHERE comissao_afiliado_id=".(int)$commissionA['id'])->fetchColumn()===-1000);
    $monthlyService->processarPagamento($event('evt_af4_refund_dup_'.$tag,$payA['id']),$refund,'PAYMENT_REFUNDED');
    $check('12 webhook repetido de estorno nao duplica ajuste',(int)$db->query("SELECT COUNT(*) FROM ajustes_afiliados WHERE comissao_afiliado_id=".(int)$commissionA['id'])->fetchColumn()===1);
    $payOpen=$payment('pay_af4_open_'.$tag,'sub_af4_b_'.$tag,20000,'RECEIVED','2026-08-20T14:00:00-03:00');$monthlyService->processarPagamento($event('evt_af4_open_'.$tag,$payOpen['id']),$payOpen,'PAYMENT_RECEIVED');$refundOpen=$payOpen;$refundOpen['status']='REFUNDED';$monthlyService->processarPagamento($event('evt_af4_open_refund_'.$tag,$payOpen['id']),$refundOpen,'PAYMENT_REFUNDED');
    $payAvailable=$payment('pay_af4_available_'.$tag,'sub_af4_b_'.$tag,20000,'RECEIVED','2026-08-01T14:00:00-03:00');$monthlyService->processarPagamento($event('evt_af4_available_'.$tag,$payAvailable['id']),$payAvailable,'PAYMENT_RECEIVED');$refundAvailable=$payAvailable;$refundAvailable['status']='REFUNDED';$monthlyService->processarPagamento($event('evt_af4_available_refund_'.$tag,$payAvailable['id']),$refundAvailable,'PAYMENT_REFUNDED');
    $unpaidReversals=$db->query("SELECT status FROM comissoes_afiliados WHERE asaas_payment_id IN ('".$payOpen['id']."','".$payAvailable['id']."') ORDER BY asaas_payment_id")->fetchAll(PDO::FETCH_COLUMN);
    $check('13 estorno antes do pagamento remove comissao aberta ou disponivel do saldo',$unpaidReversals===['ESTORNADA','ESTORNADA']&&(int)$db->query("SELECT COUNT(*) FROM ajustes_afiliados a JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id WHERE c.asaas_payment_id IN ('".$payOpen['id']."','".$payAvailable['id']."')")->fetchColumn()===0);
    $monthlyService->processarPagamento($event('evt_af4_open_late_confirmation_'.$tag,$payOpen['id']),$payOpen,'PAYMENT_RECEIVED');
    $check('14 confirmacao duplicada fora de ordem nao reativa comissao estornada',$db->query("SELECT status FROM comissoes_afiliados WHERE asaas_payment_id='".$payOpen['id']."'")->fetchColumn()==='ESTORNADA');
    $negative=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-18T14:00:00-03:00'));
    $check('15 estorno pago gera saldo negativo compensavel',$negative['saldo_disponivel_centavos']===-500&&$negative['ajustes_centavos']===-1000);
    $db->prepare('UPDATE afiliados SET percentual_comissao_bps=1500 WHERE id=:id')->execute(['id'=>$affiliateId]);
    $payNext=$payment('pay_af4_a_next_'.$tag,'sub_af4_a_'.$tag,10000,'RECEIVED','2026-09-10T14:00:00-03:00');
    $monthlyService->processarPagamento($event('evt_af4_a_next_'.$tag,$payNext['id']),$payNext,'PAYMENT_RECEIVED');
    $snapshots=$db->query("SELECT asaas_payment_id,percentual_bps,valor_comissao_centavos FROM comissoes_afiliados WHERE asaas_payment_id IN ('".$payA['id']."','".$payNext['id']."') ORDER BY id")->fetchAll();
    $check('16 alteracao percentual nao recalcula passado e futuro usa 15 por cento',(int)$snapshots[0]['valor_comissao_centavos']===1000&&(int)$snapshots[0]['percentual_bps']===1000&&(int)$snapshots[1]['valor_comissao_centavos']===1500&&(int)$snapshots[1]['percentual_bps']===1500);
    $future=$finance->summary($affiliateId,new DateTimeImmutable('2026-09-17T14:00:00-03:00'));
    $check('17 credito futuro compensa ajuste negativo',$future['saldo_disponivel_centavos']===1000);
    $db->exec('SAVEPOINT duplicate_constraint');$constraint=false;try{$db->prepare('INSERT INTO comissoes_afiliados(afiliado_id,proprietario_id,chacara_id,mensalidade_id,cobranca_mensalidade_id,asaas_payment_id,valor_base_centavos,percentual_bps,valor_comissao_centavos,confirmado_em,disponivel_em,status) SELECT afiliado_id,proprietario_id,chacara_id,mensalidade_id,cobranca_mensalidade_id,asaas_payment_id,valor_base_centavos,percentual_bps,valor_comissao_centavos,confirmado_em,disponivel_em,status FROM comissoes_afiliados WHERE id=:id')->execute(['id'=>$commissionA['id']]);}catch(PDOException){$constraint=true;$db->exec('ROLLBACK TO SAVEPOINT duplicate_constraint');}$check('18 constraint do banco impede duplicidade financeira',$constraint);
    $commissionSource=(string)file_get_contents(dirname(__DIR__).'/app/services/AffiliateCommissionService.php');$controllerSource=(string)file_get_contents(dirname(__DIR__).'/app/controllers/AdminAffiliateController.php');$routeSource=(string)file_get_contents(dirname(__DIR__).'/app/config/routes.php');
    $check('19 pagamento manual usa transacao, lock e revalidacao',str_contains($commissionSource,'beginTransaction')&&str_contains($commissionSource,'FROM afiliados WHERE id=:id FOR UPDATE')&&str_contains($commissionSource,'O valor informado é superior ao saldo disponível do afiliado.'));
    $check('20 administracao exige admin, CSRF, PRG e rota POST',str_contains($controllerSource,"Auth::requireRole('admin')")&&str_contains($controllerSource,'verify_csrf()')&&str_contains($controllerSource,"redirect('/admin/afiliados/'")&&str_contains($routeSource,"post('/admin/afiliados/{id}/pagamentos'"));
    $check('21 calculo half-up e deterministico em centavos',AffiliateCommissionService::calculateCommission(10005,1000)===1001&&AffiliateCommissionService::calculateCommission(10000,1000)===1000);
}finally{if($db->inTransaction())$db->rollBack();}
echo 'Total: '.($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;exit($fail?1:0);
