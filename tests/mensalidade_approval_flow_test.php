<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Models\Chacara;
use App\Models\ConfiguracaoMensalidadeAnuncio;
use App\Services\FakeAsaasClient;
use App\Services\MensalidadeAnuncioService;

$ok=0;$fail=0;
$check=function(string $name,bool $condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec(<<<'SQL'
CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nome TEXT,email TEXT,status TEXT);
CREATE TABLE proprietarios(id INTEGER PRIMARY KEY,usuario_id INTEGER,nome TEXT,email TEXT,telefone TEXT,status TEXT,afiliado_id INTEGER);
CREATE TABLE chacaras(id INTEGER PRIMARY KEY,proprietario_id INTEGER,nome TEXT,status_aprovacao TEXT,status_operacional TEXT);
CREATE TABLE mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,chacara_id INTEGER UNIQUE,proprietario_id INTEGER,ativa BOOLEAN,valor_centavos INTEGER,status TEXT,asaas_customer_id TEXT,asaas_subscription_id TEXT UNIQUE,proximo_vencimento TEXT,ultimo_pagamento_em TEXT,configurada_por INTEGER,ultima_falha_sincronizacao TEXT,ultima_tentativa_sincronizacao_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE cobrancas_mensalidades(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER,asaas_payment_id TEXT UNIQUE,asaas_event_id TEXT UNIQUE,valor_centavos INTEGER,status TEXT,vencimento TEXT,invoice_url TEXT,pago_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE historico_mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER,status_anterior TEXT,status_novo TEXT,valor_centavos INTEGER,origem TEXT,referencia_externa TEXT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notificacoes(id INTEGER PRIMARY KEY AUTOINCREMENT,usuario_id INTEGER,tipo TEXT,titulo TEXT,mensagem TEXT,link TEXT,chave_deduplicacao TEXT UNIQUE,lida_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE configuracoes_mensalidades_anuncios(id INTEGER PRIMARY KEY,valor_padrao_centavos INTEGER,atualizado_por INTEGER,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE historico_configuracoes_mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,valor_anterior_centavos INTEGER,valor_novo_centavos INTEGER,administrador_id INTEGER,motivo TEXT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO usuarios VALUES(1,'Proprietario','owner@test','ativo'),(9,'Admin','admin@test','ativo');
INSERT INTO proprietarios VALUES(2,1,'Proprietario','owner@test','11999999999','pendente',NULL);
INSERT INTO chacaras VALUES(3,2,'Chacara A','pendente','indisponivel'),(4,2,'Chacara B','aprovada','disponivel');
INSERT INTO configuracoes_mensalidades_anuncios(id,valor_padrao_centavos) VALUES(1,4990);
SQL);

$config=new ConfiguracaoMensalidadeAnuncio($pdo);
$check('01 valor padrao vem da configuracao auditavel',$config->valorPadraoCentavos()===4990);
$config->salvar(5990,9,'Reajuste homologado');
$check('02 alteracao do padrao e auditada',$config->valorPadraoCentavos()===5990&&(int)$pdo->query('SELECT COUNT(*) FROM historico_configuracoes_mensalidades_anuncios')->fetchColumn()===1);

$fake=new FakeAsaasClient();
$fake->cobrancasRemotas=[['id'=>'pay_a','subscription'=>'sub_fake','value'=>59.90,'status'=>'PENDING','dueDate'=>'2099-01-15','invoiceUrl'=>'https://sandbox.asaas.com/i/pay_a','externalReference'=>'mensalidade_chacara_3']];
$service=new MensalidadeAnuncioService($pdo,$fake);
$fake->simularTimeoutAposCriarAssinatura=true;$timeoutSeguro=false;
try{$service->configurar(3,true,$config->valorPadraoCentavos(),9);}catch(RuntimeException){$timeoutSeguro=$pdo->query("SELECT status FROM mensalidades_anuncios WHERE chacara_id=3")->fetchColumn()==='PENDENTE'&&$pdo->query("SELECT ultima_falha_sincronizacao FROM mensalidades_anuncios WHERE chacara_id=3")->fetchColumn()!==false;}
$check('03 timeout preserva mensalidade pendente e chacara nao aprovada',$timeoutSeguro&&$pdo->query('SELECT status_aprovacao FROM chacaras WHERE id=3')->fetchColumn()==='pendente');
$monthly=$service->configurar(3,true,$config->valorPadraoCentavos(),9);
$pdo->exec("UPDATE chacaras SET status_aprovacao='aprovada',status_operacional='disponivel' WHERE id=3");
$check('04 retry concilia assinatura remota sem duplicar',$monthly['status']==='PENDENTE'&&(int)$monthly['valor_centavos']===5990&&$fake->assinaturasCriadas===1);
$base=['status_aprovacao'=>'aprovada','status_operacional'=>'disponivel','usuario_status'=>'ativo'];
$check('05 mensalidade pendente impede publicacao',!Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'PENDENTE']));
$service->configurar(3,true,6990,9);
$check('06 valor customizado atualiza a assinatura existente',$fake->assinaturasCriadas===1&&($fake->ultimoPayloadAssinatura['value']??null)==='69.90');
$service->configurar(3,true,6990,9);
$check('07 repeticao nao duplica assinatura',$fake->assinaturasCriadas===1);

$event=static fn(string $id,string $payment):array=>['asaas_event_id'=>$id,'asaas_payment_id'=>$payment];
$payment=static fn(string $id,string $status,string $due='2099-01-15'):array=>['id'=>$id,'subscription'=>'sub_fake','value'=>69.90,'status'=>$status,'dueDate'=>$due,'externalReference'=>'mensalidade_chacara_3'];
$service->processarPagamento($event('evt-paid','pay_a'),$payment('pay_a','RECEIVED'),'PAYMENT_RECEIVED');
$check('08 confirmacao torna a chacara publica',Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>$pdo->query('SELECT status FROM mensalidades_anuncios WHERE chacara_id=3')->fetchColumn()]));
$service->processarPagamento($event('evt-overdue-new','pay_b'),$payment('pay_b','OVERDUE','2099-02-15'),'PAYMENT_OVERDUE');
$check('09 atraso posterior remove publicacao',$pdo->query('SELECT status FROM mensalidades_anuncios WHERE chacara_id=3')->fetchColumn()==='ATRASADA');
$service->processarPagamento($event('evt-regularized','pay_b'),$payment('pay_b','RECEIVED','2099-02-15'),'PAYMENT_RECEIVED');
$check('10 regularizacao restaura publicacao',$pdo->query('SELECT status FROM mensalidades_anuncios WHERE chacara_id=3')->fetchColumn()==='EM_DIA');

$pdo->exec("INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status) VALUES(4,2,1,5990,'ATRASADA')");
$check('11 mensalidades do mesmo proprietario sao independentes',Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'EM_DIA'])&&!Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'ATRASADA']));
$check('12 conta bloqueada impede publicacao',!Chacara::elegivelPublicamente(array_replace($base,['usuario_status'=>'bloqueado']),['ativa'=>true,'status'=>'EM_DIA']));
$check('13 chacara bloqueada impede publicacao',!Chacara::elegivelPublicamente(array_replace($base,['status_aprovacao'=>'bloqueada']),['ativa'=>true,'status'=>'EM_DIA']));

$controller=(string)file_get_contents(dirname(__DIR__).'/app/controllers/AdminController.php');
$check('14 aprovacao configura mensalidade antes da transacao curta de status',strpos($controller,'MensalidadeAnuncioService())->configurar')<strpos($controller,'atualizarStatusAdministrativo'));
$approvalView=(string)file_get_contents(dirname(__DIR__).'/app/views/admin/chacaras/show.php');
$check('15 aprovacao sugere o padrao mas exige valor somente no fluxo com mensalidade',str_contains($approvalView,'$valorMensalPadraoCentavos')&&str_contains($controller,'!is_string($valorMensalRecebido)')&&str_contains($controller,'if ($mensalidadeAtiva)'));
$routes=(string)file_get_contents(dirname(__DIR__).'/app/config/routes.php');
$adminView=(string)file_get_contents(dirname(__DIR__).'/app/views/admin/mensalidades/index.php');
$migration=(string)file_get_contents(dirname(__DIR__).'/database/mensalidade_anuncio_migration.sql');
$check('16 painel administrativo central possui rotas protegidas',str_contains($routes,"/admin/mensalidades")&&str_contains((string)file_get_contents(dirname(__DIR__).'/app/controllers/MensalidadeAnuncioController.php'),"requireRole('admin')"));
$check('17 painel oferece filtros e busca consolidados',str_contains($adminView,'SEM_MENSALIDADE')&&str_contains($adminView,'CANCELADA')&&str_contains($adminView,'name="busca"'));
$check('18 configuracao padrao exige minimo e guarda historico',str_contains($migration,'valor_padrao_centavos >= 100')&&str_contains($migration,'historico_configuracoes_mensalidades_anuncios'));
$check('19 bloqueio e rejeicao nao cancelam assinatura automaticamente',!str_contains($controller,'cancelarAssinatura'));

echo 'Total: '.($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;
exit($fail?1:0);
