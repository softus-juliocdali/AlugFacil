<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Models\Chacara;
use App\Services\FakeAsaasClient;
use App\Services\MensalidadeAnuncioService;

$ok=0;$fail=0;
$check=function(string $name,bool $condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};

putenv('APP_ENV=local');
putenv('ASAAS_ENVIRONMENT=sandbox');
putenv('ASAAS_API_KEY=$aact_test_only');
putenv('ASAAS_ALLOW_SANDBOX_MUTATIONS=true');

$db=static function():PDO{
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
        CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nome TEXT,email TEXT,status TEXT);
        CREATE TABLE proprietarios(id INTEGER PRIMARY KEY,usuario_id INTEGER,nome TEXT,email TEXT,telefone TEXT,status TEXT);
        CREATE TABLE chacaras(id INTEGER PRIMARY KEY,proprietario_id INTEGER,nome TEXT,status_aprovacao TEXT,status_operacional TEXT);
        CREATE TABLE mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,chacara_id INTEGER NOT NULL UNIQUE,proprietario_id INTEGER NOT NULL,ativa BOOLEAN NOT NULL,valor_centavos INTEGER,status TEXT NOT NULL,asaas_customer_id TEXT,asaas_subscription_id TEXT UNIQUE,proximo_vencimento TEXT,ultimo_pagamento_em TEXT,configurada_por INTEGER,ultima_falha_sincronizacao TEXT,ultima_tentativa_sincronizacao_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE cobrancas_mensalidades(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER NOT NULL,asaas_payment_id TEXT NOT NULL UNIQUE,asaas_event_id TEXT UNIQUE,valor_centavos INTEGER NOT NULL,status TEXT NOT NULL,vencimento TEXT,invoice_url TEXT,pago_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE historico_mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER,status_anterior TEXT,status_novo TEXT,valor_centavos INTEGER,origem TEXT,referencia_externa TEXT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE notificacoes(id INTEGER PRIMARY KEY AUTOINCREMENT,usuario_id INTEGER,tipo TEXT,titulo TEXT,mensagem TEXT,link TEXT,chave_deduplicacao TEXT UNIQUE,lida_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO usuarios VALUES(1,'Proprietario','owner@example.test','ativo'),(9,'Admin','admin@example.test','ativo');
        INSERT INTO proprietarios VALUES(2,1,'Proprietario','owner@example.test','11999999999','ativo');
        INSERT INTO chacaras VALUES(3,2,'Chacara Teste','aprovada','disponivel');
        SQL);
    return $pdo;
};
$payment=static fn(string $id='pay_first',string $status='PENDING'):array=>['id'=>$id,'subscription'=>'sub_fake','value'=>49.90,'status'=>$status,'dueDate'=>'2099-01-15','invoiceUrl'=>'https://sandbox.asaas.com/i/'.$id,'externalReference'=>'mensalidade_chacara_3'];
$event=static fn(string $id,string $paymentId):array=>['asaas_event_id'=>$id,'asaas_payment_id'=>$paymentId];

$pdo=$db();$fake=new FakeAsaasClient();$fake->cobrancasRemotas=[$payment()];$service=new MensalidadeAnuncioService($pdo,$fake);
$registro=$service->configurar(3,true,4990,9);
$charge=$pdo->query('SELECT * FROM cobrancas_mensalidades')->fetch(PDO::FETCH_ASSOC);
$check('01 cria a assinatura uma unica vez',$fake->assinaturasCriadas===1&&$registro['asaas_subscription_id']==='sub_fake');
$check('02 consulta cobrancas filtrando pela assinatura',$fake->consultasCobrancas===1);
$check('03 registra a primeira cobranca',is_array($charge)&&$charge['asaas_payment_id']==='pay_first'&&(int)$charge['valor_centavos']===4990);
$check('04 salva vencimento e URL de pagamento',$charge['vencimento']==='2099-01-15'&&$charge['invoice_url']==='https://sandbox.asaas.com/i/pay_first');
$check('05 assinatura criada nao marca mensalidade como paga',$registro['status']==='PENDENTE'&&$charge['status']==='PENDENTE');
$service->configurar(3,true,4990,9);
$check('06 repeticao nao cria segunda assinatura',$fake->assinaturasCriadas===1);
$check('07 repeticao nao cria segunda cobranca',(int)$pdo->query('SELECT COUNT(*) FROM cobrancas_mensalidades')->fetchColumn()===1);
$check('08 repeticao nao duplica historico',(int)$pdo->query('SELECT COUNT(*) FROM historico_mensalidades_anuncios')->fetchColumn()===1);
$service->processarPagamento($event('evt-created','pay_first'),$payment(),'PAYMENT_CREATED');
$check('09 webhook posterior atualiza sem duplicar',(int)$pdo->query('SELECT COUNT(*) FROM cobrancas_mensalidades')->fetchColumn()===1);
$service->processarPagamento($event('evt-confirmed','pay_first'),$payment('pay_first','CONFIRMED'),'PAYMENT_CONFIRMED');
$check('10 confirmacao por webhook marca cobranca paga',$pdo->query("SELECT status FROM cobrancas_mensalidades WHERE asaas_payment_id='pay_first'")->fetchColumn()==='PAGA');
$monthly=$pdo->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch(PDO::FETCH_ASSOC);
$check('11 somente confirmacao por webhook deixa em dia',$monthly['status']==='EM_DIA');
$base=['status_aprovacao'=>'aprovada','status_operacional'=>'disponivel','proprietario_status'=>'ativo','usuario_status'=>'ativo'];
$check('12 pagamento confirmado libera anuncio',Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>$monthly['status']]));

$pdo2=$db();$fake2=new FakeAsaasClient();$service2=new MensalidadeAnuncioService($pdo2,$fake2);
$service2->configurar(3,true,4990,9);
$check('13 ausencia temporaria nao falha nem cria cobranca',(int)$pdo2->query('SELECT COUNT(*) FROM cobrancas_mensalidades')->fetchColumn()===0);
$service2->processarPagamento($event('evt-late-created','pay_late'),$payment('pay_late'),'PAYMENT_CREATED');
$check('14 webhook cria cobranca que ainda nao existia',(int)$pdo2->query('SELECT COUNT(*) FROM cobrancas_mensalidades')->fetchColumn()===1);
$service2->processarPagamento($event('evt-overdue','pay_late'),$payment('pay_late','OVERDUE'),'PAYMENT_OVERDUE');
$late=$pdo2->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch(PDO::FETCH_ASSOC);
$check('15 inadimplencia continua bloqueando anuncio',$late['status']==='ATRASADA'&&!Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>$late['status']]));
$service2->processarPagamento($event('evt-late-confirmed','pay_late'),$payment('pay_late','RECEIVED'),'PAYMENT_RECEIVED');
$late=$pdo2->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch(PDO::FETCH_ASSOC);
$check('16 pagamento posterior continua liberando anuncio',$late['status']==='EM_DIA'&&Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>$late['status']]));
$service2->processarPagamento($event('evt-out-of-order','pay_late'),$payment('pay_late','OVERDUE'),'PAYMENT_OVERDUE');
$late=$pdo2->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch(PDO::FETCH_ASSOC);
$check('17 evento atrasado fora de ordem nao regride pagamento confirmado',$late['status']==='EM_DIA'&&$pdo2->query("SELECT status FROM cobrancas_mensalidades WHERE asaas_payment_id='pay_late'")->fetchColumn()==='PAGA');

echo 'Total: '.($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;
exit($fail?1:0);
