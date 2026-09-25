<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/PublicApiTest.php';
require __DIR__.'/support/FinancialFixture.php';
require __DIR__.'/support/FinancialGatewayFake.php';
use App\Core\Database;
use App\Services\MonthlyCardPayment;
use App\Services\MonthlyBillingService;
use App\Services\MonthlyPaymentPresentation;

$db=Database::getConnection();$f=new FinancialFixture($db);$fake=new FinancialGatewayFake($f->tag);
$service=new MonthlyCardPayment($db,$fake);
$input=['holderName'=>'Test only','number'=>'4111111111111111','expiryMonth'=>'12','expiryYear'=>'2035','ccv'=>'123','name'=>'Test only','email'=>'test@example.test','cpfCnpj'=>FinancialFixture::cpf(),'postalCode'=>'01001000','addressNumber'=>'1','phone'=>'11999999999'];
$rejected=function(callable $call,string $message){try{$call();}catch(RuntimeException|DomainException){apiCheck(true,$message);return;}throw new RuntimeException('FAIL: '.$message);};
$insert=function(int $month)use($db,$f):int{$s=$db->prepare("INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,vencimento,valor_centavos,versao_global,versao_imovel,percentual_afiliado_bps,comissao_afiliado_centavos) VALUES(:c,:p,:d,1500,1,1,0,0) RETURNING id");$s->execute(['c'=>$f->property,'p'=>$f->owner,'d'=>'2030-'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'-01']);return (int)$s->fetchColumn();};
try {
    $id=$insert(1);
    $rejected(fn()=>$service->pay($id,$f->owner+1,$input,'127.0.0.1'),'Ownership before gateway');
    $rejected(fn()=>$service->pay($id,$f->owner,[],'127.0.0.1'),'Invalid card never issues charge');
    apiCheck($fake->paymentPosts===0,'Zero posts after rejected inputs');
    $first=$service->pay($id,$f->owner,$input,'127.0.0.1');
    $service->pay($id,$f->owner,$input,'127.0.0.1');
    $view=(new MonthlyPaymentPresentation($db,$fake))->get($id,$f->owner);
    apiCheck($first['status']==='CONFIRMED' && $view['estado']==='CONFIRMED' && $fake->paymentPosts===1 && $fake->cardPosts===1,'Repeat and reopen reuse single captured monthly charge');
    $q=$db->prepare('SELECT payload::text,resultado::text FROM operacoes_financeiras WHERE conta_gateway=:s');$q->execute(['s'=>$f->tag]);$stored=json_encode($q->fetchAll());
    apiCheck(!str_contains($stored,$input['number'])&&!str_contains($stored,'creditCard')&&!str_contains($stored,'must-not-persist'),'PAN, CVV and token absent from durable intents/results');
    $rejected(fn()=>(new MonthlyBillingService($db,$fake))->issue($id,$f->owner,'PIX'),'Cannot replace existing card with PIX');
    $id2=$insert(2);$fake->cardMode='timeout';$before=$fake->cardPosts;
    $rejected(fn()=>$service->pay($id2,$f->owner,$input,'127.0.0.1'),'Capture timeout recorded');
    $rejected(fn()=>$service->pay($id2,$f->owner,$input,'127.0.0.1'),'Unknown capture not resent even when gateway still pending');
    apiCheck($fake->cardPosts===$before+1,'No duplicate after ambiguous response');
    $id3=$insert(3);$fake->cardMode='timeout_after';$before=$fake->cardPosts;
    $rejected(fn()=>$service->pay($id3,$f->owner,$input,'127.0.0.1'),'Lost response after successful capture');
    apiCheck($service->pay($id3,$f->owner,$input,'127.0.0.1')['status']==='CONFIRMED' && $fake->cardPosts===$before+1,'GET reconciles captured charge without second POST');
    $id4=$insert(4);$fake->cardMode='declined';$before=$fake->cardPosts;
    $rejected(fn()=>$service->pay($id4,$f->owner,$input,'127.0.0.1'),'Declined card recorded');
    $rejected(fn()=>$service->pay($id4,$f->owner,$input,'127.0.0.1'),'Declined operation not blindly repeated');
    apiCheck($fake->cardPosts===$before+1,'One capture attempt on decline');
    $id6=$insert(6);$fake->cardMode='success';
    $config=require APP_ROOT.'/app/config/database.php';
    $other=new PDO('pgsql:host='.$config['DB_HOST'].';port='.$config['DB_PORT'].';dbname='.$config['DB_NAME'],$config['DB_USER'],$config['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $fake->duringCard=function()use($other,$id6,$f,$fake,$input,$rejected){$rejected(fn()=>(new MonthlyCardPayment($other,$fake))->pay($id6,$f->owner,$input,'127.0.0.1'),'Concurrent connection cannot capture same monthly charge');};
    $before=$fake->cardPosts;$service->pay($id6,$f->owner,$input,'127.0.0.1');$fake->duringCard=null;
    apiCheck($fake->cardPosts===$before+1,'Advisory lock permits only one concurrent capture');
    $id5=$insert(5);$before=$db->query('SELECT count(*) FROM operacoes_financeiras')->fetchColumn();
    $rejected(fn()=>(new MonthlyBillingService($db))->issue($id5,$f->owner,'PIX'),'Effective environment blocks monthly PIX before outbound work');
    $rejected(fn()=>(new MonthlyCardPayment($db))->pay($id5,$f->owner,$input,'127.0.0.1'),'Effective environment blocks card capture');
    $row=$db->query('SELECT forma_pagamento,asaas_payment_id FROM obrigacoes_mensalidades WHERE id='.$id5)->fetch();
    apiCheck($row['forma_pagamento']===null && $row['asaas_payment_id']===null && $db->query('SELECT count(*) FROM operacoes_financeiras')->fetchColumn()===$before,'Policy denial does not choose method or create financial intent');
} finally {
    foreach(['cobrancas_mensalidades','obrigacoes_mensalidades','mensalidades_anuncios'] as $table) {
        $sql=$table==='cobrancas_mensalidades'?'DELETE FROM cobrancas_mensalidades WHERE obrigacao_id IN (SELECT id FROM obrigacoes_mensalidades WHERE chacara_id=:c)':'DELETE FROM '.$table.' WHERE chacara_id=:c';
        $db->prepare($sql)->execute(['c'=>$f->property]);
    }
    $f->close();
}
