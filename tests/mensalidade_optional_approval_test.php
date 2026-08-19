<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Models\Chacara;
use App\Services\FakeAsaasClient;
use App\Services\MensalidadeAnuncioService;

$ok=0;$fail=0;
$check=function(string $name,bool $condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec(<<<'SQL'
CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nome TEXT,email TEXT,status TEXT);
CREATE TABLE proprietarios(id INTEGER PRIMARY KEY,usuario_id INTEGER,nome TEXT,email TEXT,telefone TEXT,status TEXT);
CREATE TABLE chacaras(id INTEGER PRIMARY KEY,proprietario_id INTEGER,nome TEXT,status_aprovacao TEXT,status_operacional TEXT);
CREATE TABLE mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,chacara_id INTEGER NOT NULL UNIQUE,proprietario_id INTEGER NOT NULL,ativa BOOLEAN NOT NULL,valor_centavos INTEGER,status TEXT NOT NULL,asaas_customer_id TEXT,asaas_subscription_id TEXT UNIQUE,proximo_vencimento TEXT,ultimo_pagamento_em TEXT,configurada_por INTEGER,ultima_falha_sincronizacao TEXT,ultima_tentativa_sincronizacao_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE cobrancas_mensalidades(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER NOT NULL,asaas_payment_id TEXT NOT NULL UNIQUE,asaas_event_id TEXT UNIQUE,valor_centavos INTEGER NOT NULL,status TEXT NOT NULL,vencimento TEXT,invoice_url TEXT,pago_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE historico_mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER,status_anterior TEXT,status_novo TEXT,valor_centavos INTEGER,origem TEXT,referencia_externa TEXT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notificacoes(id INTEGER PRIMARY KEY AUTOINCREMENT,usuario_id INTEGER,tipo TEXT,titulo TEXT,mensagem TEXT,link TEXT,chave_deduplicacao TEXT UNIQUE,lida_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE reservas(id INTEGER PRIMARY KEY,chacara_id INTEGER,status_reserva TEXT);
INSERT INTO usuarios VALUES(1,'Proprietario','owner@example.test','ativo'),(9,'Admin','admin@example.test','ativo');
INSERT INTO proprietarios VALUES(2,1,'Proprietario','owner@example.test','11999999999','ativo');
INSERT INTO chacaras VALUES(3,2,'Sem mensalidade','pendente','indisponivel'),(4,2,'Outra chacara','aprovada','disponivel');
INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status) VALUES(4,2,1,4990,'ATRASADA');
INSERT INTO reservas VALUES(10,3,'confirmada');
SQL);

$fake=new FakeAsaasClient();
$service=new MensalidadeAnuncioService($pdo,$fake);
$sem=$service->configurar(3,false,999999,9);
$pdo->exec("UPDATE chacaras SET status_aprovacao='aprovada',status_operacional='disponivel' WHERE id=3");
$base=['status_aprovacao'=>'aprovada','status_operacional'=>'disponivel','usuario_status'=>'ativo'];
$check('01 aprovacao sem mensalidade registra estado inativo',!in_array($sem['ativa'],[true,1,'1','t','true'],true)&&$sem['status']==='SEM_MENSALIDADE'&&$sem['valor_centavos']===null);
$check('02 valor antigo e ignorado no fluxo sem mensalidade',(int)$pdo->query('SELECT valor_centavos IS NULL FROM mensalidades_anuncios WHERE chacara_id=3')->fetchColumn()===1);
$check('03 sem mensalidade nao consulta nem cria recursos Asaas',$fake->consultasClientes===0&&$fake->clientesCriados===0&&$fake->consultasAssinaturas===0&&$fake->assinaturasCriadas===0&&$fake->consultasCobrancas===0&&$fake->cobrancasCriadas===0);
$check('04 aprovada disponivel sem mensalidade e publica',Chacara::elegivelPublicamente($base,$sem));

$clause=Chacara::clausulaElegibilidadePublica();
$publicIds=$pdo->query("SELECT c.id FROM chacaras c INNER JOIN proprietarios p ON p.id=c.proprietario_id INNER JOIN usuarios u ON u.id=p.usuario_id WHERE $clause ORDER BY c.id")->fetchAll(PDO::FETCH_COLUMN);
$check('05 SQL publica somente a chacara elegivel',array_map('intval',$publicIds)===[3]);
$check('06 atraso de outra chacara do proprietario nao interfere',Chacara::elegivelPublicamente($base,$sem)&&!Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'ATRASADA']));

$fake->cobrancasRemotas=[['id'=>'pay_monthly','subscription'=>'sub_fake','value'=>49.90,'status'=>'PENDING','dueDate'=>'2099-01-15','invoiceUrl'=>'https://sandbox.asaas.com/i/pay_monthly','externalReference'=>'mensalidade_chacara_3']];
$pending=$service->configurar(3,true,4990,9);
$check('07 com mensalidade inicia pendente e bloqueia publicacao',$pending['status']==='PENDENTE'&&!Chacara::elegivelPublicamente($base,$pending));
$check('08 com mensalidade cria uma unica assinatura sem cobranca avulsa',$fake->assinaturasCriadas===1&&$fake->cobrancasCriadas===0);

$event=static fn(string $id,string $paymentId):array=>['asaas_event_id'=>$id,'asaas_payment_id'=>$paymentId];
$payment=static fn(string $status):array=>['id'=>'pay_monthly','subscription'=>'sub_fake','value'=>49.90,'status'=>$status,'dueDate'=>'2099-01-15','externalReference'=>'mensalidade_chacara_3'];
$service->processarPagamento($event('evt-paid','pay_monthly'),$payment('RECEIVED'),'PAYMENT_RECEIVED');
$emDia=$pdo->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch();
$check('09 pagamento em dia restaura publicacao',$emDia['status']==='EM_DIA'&&Chacara::elegivelPublicamente($base,$emDia));
$service->processarPagamento($event('evt-refund','pay_monthly'),$payment('REFUNDED'),'PAYMENT_REFUNDED');
$atrasada=$pdo->query('SELECT * FROM mensalidades_anuncios WHERE chacara_id=3')->fetch();
$check('10 mensalidade atrasada bloqueia publicacao',$atrasada['status']==='ATRASADA'&&!Chacara::elegivelPublicamente($base,$atrasada));

$createsBefore=[$fake->clientesCriados,$fake->assinaturasCriadas,$fake->cobrancasCriadas];
$desativada=$service->configurar(3,false,888888,9);
$createsAfter=[$fake->clientesCriados,$fake->assinaturasCriadas,$fake->cobrancasCriadas];
$check('11 desativacao volta a publicar e nao apaga historico',$desativada['status']==='SEM_MENSALIDADE'&&Chacara::elegivelPublicamente($base,$desativada)&&(int)$pdo->query('SELECT COUNT(*) FROM historico_mensalidades_anuncios WHERE mensalidade_id='.$desativada['id'])->fetchColumn()>=4);
$check('12 desativacao nao cria cliente assinatura ou cobranca',$createsBefore===$createsAfter&&$fake->assinaturasCanceladas===1);
$check('13 alteracao da mensalidade nao cancela reservas',(int)$pdo->query('SELECT COUNT(*) FROM reservas WHERE id=10 AND status_reserva=\'confirmada\'')->fetchColumn()===1);

$root=dirname(__DIR__);
$controller=(string)file_get_contents($root.'/app/controllers/AdminController.php');
$view=(string)file_get_contents($root.'/app/views/admin/chacaras/show.php');
$routes=(string)file_get_contents($root.'/app/config/routes.php');
$panelCss=(string)file_get_contents($root.'/public/assets/css/panel.css');
$reserva=(string)file_get_contents($root.'/app/models/Reserva.php');
$check('14 aprovacao sem escolha explicita e bloqueada no backend',str_contains($controller,'!is_string($tipoMensalidade)')&&str_contains($controller,"['sem', 'com']")&&str_contains($controller,'Escolha se o imovel sera aprovado com ou sem mensalidade.'));
$check('15 controller so valida valor quando mensalidade esta ativa',strpos($controller,'if ($mensalidadeAtiva)')<strpos($controller,'decimalParaCentavos'));
$check('16 tela oferece cards acessiveis e valor condicional',substr_count($view,'class="approval-monthly-radio"')===2&&str_contains($view,'name="mensalidade" value="sem" required')&&str_contains($view,'name="mensalidade" value="com" required')&&str_contains($view,'valueInput.required = withMonthlyFee')&&str_contains($view,'.hidden = !withMonthlyFee'));
$check('17 novas reservas reutilizam a mesma elegibilidade',str_contains($reserva,'clausulaElegibilidadePublica'));
$check('18 tela possui um unico grupo de mensalidade e nenhum botao separado',substr_count($view,'<fieldset class="form-full approval-monthly-choice">')===1&&substr_count($view,'class="approval-monthly-radio"')===2&&!str_contains($view,'Salvar mensalidade')&&!str_contains($view,"/mensalidade')"));
$check('19 somente o fluxo de aprovacao persiste a modalidade',str_contains($routes,"/admin/chacaras/{id}/status")&&!str_contains($routes,"/admin/chacaras/{id}/mensalidade"));
$check('20 cards mantem alinhamento, foco e responsividade sem overflow',str_contains($panelCss,'.approval-monthly-options { display: grid;')&&str_contains($panelCss,'.approval-monthly-option { position: relative; min-width: 0;')&&str_contains($panelCss,'.approval-monthly-radio:focus-visible + .approval-monthly-card')&&str_contains($panelCss,'.approval-monthly-radio:checked + .approval-monthly-card')&&str_contains($panelCss,'.approval-monthly-options { grid-template-columns: 1fr; }'));

echo 'Total: '.($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;
exit($fail?1:0);
