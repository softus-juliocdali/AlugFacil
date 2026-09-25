<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/PublicApiTest.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Models\AsaasWebhookEvento;
use App\Services\{MonthlyBillingService,FinancialReleasePolicy,SandboxFinancialWorker};
$db=Database::getConnection();$f=new FinancialFixture($db);$fake=new FinancialGatewayFake($f->tag);$oid=0;$eventIds=[];
$path=tempnam(sys_get_temp_dir(),'monthly-worker-');chmod($path,0600);
$env=['FINANCIAL_RELEASE_MODE'=>'sandbox-test','FINANCIAL_TEST_REGISTRY'=>$path,'ASAAS_ENVIRONMENT'=>'sandbox','ASAAS_BASE_URL'=>'https://api-sandbox.asaas.com/v3','ASAAS_SANDBOX_API_KEY'=>'$aact_hmlg_'.str_repeat('x',40),'ASAAS_ALLOW_SANDBOX_MUTATIONS'=>'true'];$saved=[];
try{
 foreach($env as $k=>$v){$saved[$k]=getenv($k);putenv($k.'='.$v);}
 $db->prepare("UPDATE chacaras SET status_operacional='indisponivel' WHERE id=:id")->execute(['id'=>$f->property]);
 $q=$db->prepare('INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,vencimento,valor_centavos,versao_global,versao_imovel,percentual_afiliado_bps,comissao_afiliado_centavos) VALUES(:c,:p,CURRENT_DATE,1500,1,1,0,0) RETURNING id');$q->execute(['c'=>$f->property,'p'=>$f->owner]);$oid=(int)$q->fetchColumn();
 $o=(new MonthlyBillingService($db,$fake))->issue($oid,$f->owner,'CREDIT_CARD');
 $payment=$fake->payments[$o['asaas_payment_id']];$payment['status']='CONFIRMED';
 $inbox=new AsaasWebhookEvento($db);$event=['id'=>'evt_'.$f->tag.'_confirmed','event'=>'PAYMENT_CONFIRMED','payment'=>$payment];
 $e=$inbox->receber($event,json_encode($event));$eventIds[]=(int)$e['event']['id'];
 $registry=['environment'=>'sandbox','expires_at'=>time()+1200,'users'=>[],'owners'=>[],'properties'=>[]];
 file_put_contents($path,json_encode($registry));$worker=new SandboxFinancialWorker($db,$fake);$worker->run();
 $get=fn()=>$db->query('SELECT estado FROM obrigacoes_mensalidades WHERE id='.$oid)->fetchColumn();
 apiCheck($get()==='pendente','unregistered monthly webhook not processed');
 $registry['monthly_card_tests']=[['synthetic'=>true,'obligation_id'=>$oid,'owner_id'=>$f->owner,'user_id'=>$f->users['proprietario'],'property_id'=>$f->property,'value_centavos'=>1500,'expires_at'=>time()+600]];file_put_contents($path,json_encode($registry));
 $result=$worker->run();apiCheck($get()==='paga' && $result['webhooks']===1,'registered monthly confirmation processed by worker');
 apiCheck($db->query('SELECT status FROM cobrancas_mensalidades WHERE obrigacao_id='.$oid)->fetchColumn()==='PAGA','billing state paid');
 apiCheck($db->query('SELECT status FROM mensalidades_anuncios WHERE chacara_id='.$f->property)->fetchColumn()==='EM_DIA','listing monthly up to date');
 $worker->run();apiCheck((int)$db->query('SELECT quantidade_tentativas FROM asaas_webhook_eventos WHERE id='.$eventIds[0])->fetchColumn()===1,'worker repeat does not process same event twice');
 $event['id']='evt_'.$f->tag.'_updated';$event['event']='PAYMENT_UPDATED';$e=$inbox->receber($event,json_encode($event));$eventIds[]=(int)$e['event']['id'];
 $result=$worker->run();apiCheck($result['webhooks']===1 && $get()==='paga','later event on paid monthly still processed without downgrade');
 apiCheck($fake->cardPosts===0 && $fake->paymentPosts===1 && $fake->refundPosts===0 && $fake->transferPosts===0,'monthly worker performs no gateway mutations');
 $registry['monthly_card_tests'][0]['expires_at']=time()-1;file_put_contents($path,json_encode($registry));apiCheck(FinancialReleasePolicy::monthlyCardWebhookObligations()===[],'expired authorization cannot process monthly events');
 echo "PASS: monthly worker integration with local fake; real gateway tested separately.\n";
}finally{
 foreach($saved as $k=>$v)putenv($v===false?$k:$k.'='.$v);unlink($path);
 foreach($eventIds as $id)$db->prepare('DELETE FROM asaas_webhook_eventos WHERE id=:id')->execute(['id'=>$id]);
 foreach(['cobrancas_mensalidades','mensalidades_anuncios','obrigacoes_mensalidades'] as $t){$col=$t==='cobrancas_mensalidades'?'obrigacao_id':'chacara_id';$db->prepare('DELETE FROM '.$t.' WHERE '.$col.'=:id')->execute(['id'=>$t==='cobrancas_mensalidades'?$oid:$f->property]);}
 $f->close();
}
