<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/PublicApiTest.php';
require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;
use App\Services\{FinancialReleasePolicy as Policy,FinancialOperationService as Operations};
$db=Database::getConnection();$f=new FinancialFixture($db);$path=tempnam(sys_get_temp_dir(),'monthly-policy-');chmod($path,0600);
$keys=['FINANCIAL_RELEASE_MODE','FINANCIAL_TEST_REGISTRY','ASAAS_ENVIRONMENT','ASAAS_BASE_URL','ASAAS_SANDBOX_API_KEY','ASAAS_ALLOW_SANDBOX_MUTATIONS'];$saved=[];foreach($keys as $k)$saved[$k]=getenv($k);
$oid=0;
try {
 foreach(['FINANCIAL_RELEASE_MODE'=>'sandbox-test','FINANCIAL_TEST_REGISTRY'=>$path,'ASAAS_ENVIRONMENT'=>'sandbox','ASAAS_BASE_URL'=>'https://api-sandbox.asaas.com/v3','ASAAS_SANDBOX_API_KEY'=>'$aact_hmlg_'.str_repeat('x',40),'ASAAS_ALLOW_SANDBOX_MUTATIONS'=>'true'] as $k=>$v)putenv($k.'='.$v);
 $db->prepare("UPDATE chacaras SET status_operacional='indisponivel' WHERE id=:id")->execute(['id'=>$f->property]);
 $q=$db->prepare("INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,vencimento,valor_centavos,versao_global,versao_imovel,percentual_afiliado_bps,comissao_afiliado_centavos) VALUES(:c,:p,CURRENT_DATE,1500,1,1,0,0) RETURNING id");$q->execute(['c'=>$f->property,'p'=>$f->owner]);$oid=(int)$q->fetchColumn();
 $test=['synthetic'=>true,'obligation_id'=>$oid,'owner_id'=>$f->owner,'user_id'=>$f->users['proprietario'],'property_id'=>$f->property,'value_centavos'=>1500,'expires_at'=>time()+600];
 $registry=['environment'=>'sandbox','expires_at'=>time()+1200,'users'=>[],'owners'=>[],'properties'=>[]];
 $write=function(array $r)use($path):void{file_put_contents($path,json_encode($r));};
 $denied=function(callable $fn,string $label):void{try{$fn();}catch(RuntimeException){apiCheck(true,$label);return;}throw new RuntimeException('FAIL: '.$label);};
 $allow=fn()=>Policy::assertMonthlyPaymentsAllowed($oid,$f->owner,'CREDIT_CARD');
 $write($registry);$denied($allow,'unregistered obligation blocked');
 $registry['monthly_card_tests']=[$test];$write($registry);$allow();apiCheck(true,'explicit synthetic obligation allowed');
 $denied(fn()=>Policy::assertMonthlyPaymentsAllowed($oid,$f->owner+1,'CREDIT_CARD'),'different owner blocked');
 $denied(fn()=>Policy::assertMonthlyPaymentsAllowed($oid,$f->owner,'PIX'),'PIX remains blocked');
 foreach(['synthetic'=>false,'expires_at'=>time()-1,'user_id'=>$f->users['cliente'],'property_id'=>$f->property+1,'value_centavos'=>1501,'obligation_id'=>$oid+1] as $k=>$v){$r=$registry;$r['monthly_card_tests'][0][$k]=$v;$write($r);$denied($allow,'invalid permission '.$k);}
 $write($registry);$db->prepare("UPDATE chacaras SET status_operacional='disponivel' WHERE id=:id")->execute(['id'=>$f->property]);$denied($allow,'public property blocked');$db->prepare("UPDATE chacaras SET status_operacional='indisponivel' WHERE id=:id")->execute(['id'=>$f->property]);
 $db->prepare("UPDATE obrigacoes_mensalidades SET estado='paga' WHERE id=:id")->execute(['id'=>$oid]);$denied($allow,'nonpending capture blocked');$db->prepare("UPDATE obrigacoes_mensalidades SET estado='pendente' WHERE id=:id")->execute(['id'=>$oid]);
 $customer=(new Operations($f->tag,$db))->executar('customer','fixture',1,[],fn()=>[],fn()=>['id'=>'cus_synthetic_policy']);
 $db->prepare("INSERT INTO asaas_customers(usuario_id,ambiente,conta_gateway,asaas_customer_id,documento_hash,operacao_id) VALUES(:u,'sandbox',:s,'cus_synthetic_policy',:h,:op)")->execute(['u'=>$f->users['proprietario'],'s'=>$f->tag,'h'=>hash('sha256','test'),'op'=>$customer['_operation_id']]);
 $ops=new Operations($f->tag,$db);$version=0;
 $check=function(string $type,string $entity,string $path,array $payload,bool $allowed)use($ops,&$version):void{
  $result=$ops->executar($type,$entity,++$version,[],fn()=>[],function()use($path,$payload,$allowed):array{
   $pass=true;try{Policy::assertExternalMutationAllowed('POST',$path,$payload);}catch(RuntimeException){$pass=false;}
   apiCheck($pass===$allowed,'outbound policy '.$path.' '.($allowed?'allows scoped operation':'denies mismatch'));return ['id'=>'test_policy'];
  });
 };
 $check('customer','usuario:'.$f->users['proprietario'],'/customers',['notificationDisabled'=>true],true);
 $check('customer','usuario:'.$f->users['cliente'],'/customers',['notificationDisabled'=>true],false);
 $check('customer','usuario:'.$f->users['proprietario'],'/customers',['notificationDisabled'=>false],false);
 $payload=['customer'=>'cus_synthetic_policy','billingType'=>'CREDIT_CARD','dueDate'=>date('Y-m-d'),'value'=>'15.00'];
 $check('cobranca','mensalidade:'.$oid,'/payments',$payload,true);
 foreach(['customer'=>'cus_other','billingType'=>'PIX','dueDate'=>'2099-01-01','value'=>'16.00','split'=>[]] as $k=>$v)$check('cobranca','mensalidade:'.$oid,'/payments',array_replace($payload,[$k=>$v]),false);
 $check('checkout','mensalidade-cartao:'.$oid,'/payments/pay_policy/payWithCreditCard',[],false);
 $db->prepare("UPDATE obrigacoes_mensalidades SET forma_pagamento='CREDIT_CARD',asaas_payment_id='pay_policy' WHERE id=:id")->execute(['id'=>$oid]);
 $check('checkout','mensalidade-cartao:'.$oid,'/payments/pay_policy/payWithCreditCard',[],true);
 $check('checkout','mensalidade-cartao:'.$oid,'/payments/pay_other/payWithCreditCard',[],false);
 $check('checkout','mensalidade-cartao:'.$oid,'/transfers',[],false);
 $check('cobranca','mensalidade:'.($oid+1),'/payments',$payload,false);
 echo "PASS: monthly card Sandbox scope; no gateway requests performed.\n";
}finally{
 foreach($saved as $k=>$v)putenv($v===false?$k:$k.'='.$v);if(is_file($path))unlink($path);
 if($oid)$db->prepare('DELETE FROM obrigacoes_mensalidades WHERE id=:id')->execute(['id'=>$oid]);$f->close();
}
