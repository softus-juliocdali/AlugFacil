<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require dirname(__DIR__).'/database/MigrationExecutionGuard.php';
use App\Models\Chacara;
use App\Services\FakeAsaasClient;

$ok=0;$fail=0;
$check=function(string $name,bool $condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$chacara=$read('app/models/Chacara.php');$reserva=$read('app/models/Reserva.php');$webhook=$read('app/services/AsaasWebhookService.php');$monthly=$read('app/services/MensalidadeAnuncioService.php');$routes=$read('app/config/routes.php');$migration=$read('database/mensalidade_anuncio_migration.sql');$owner=$read('app/models/MensalidadeAnuncio.php');$controller=$read('app/controllers/MensalidadeAnuncioController.php');$view=$read('app/views/proprietario/mensalidades/index.php');

$clause=Chacara::clausulaElegibilidadePublica();
$check('01 sem mensalidade nao exige registro',str_contains($clause,'NOT EXISTS')&&str_contains($clause,'ma_publica.ativa=TRUE'));
$check('02 mensalidade em dia elegivel',str_contains($clause,"status<>'EM_DIA'"));
$check('03 mensalidade atrasada bloqueia exposicao',str_contains($clause,"ma_publica.status<>'EM_DIA'"));
$check('04 aprovacao legada do proprietario nao interfere e conta precisa estar ativa',!str_contains($clause,"p.status = 'ativo'")&&str_contains($clause,"u.status = 'ativo'"));
$check('05 mesma regra protege novas reservas',str_contains($reserva,'clausulaElegibilidadePublica'));
$check('06 area privada nao usa elegibilidade publica',str_contains($owner,'WHERE m.proprietario_id=:proprietario')&&!str_contains($owner,'clausulaElegibilidadePublica'));
$check('07 notificacao de atraso e regularizacao',str_contains($monthly,"\$novo==='ATRASADA'")&&str_contains($monthly,"\$novo==='EM_DIA'"));
$check('08 webhook separa mensalidade antes de reserva',strpos($webhook,'processarPagamento')<strpos($webhook,'SELECT r.*'));
$check('09 fluxo mensal nao escreve reservas ou pagamentos',!preg_match('/\b(?:UPDATE|INSERT INTO|DELETE FROM)\s+(?:reservas|pagamentos)\b/i',$monthly));
$check('10 fluxo de reserva permanece com referencia propria',str_contains($read('app/helpers/AsaasHelper.php'),"'reserva_'"));
$check('11 somente rota administrativa altera configuracao',str_contains($routes,"/admin/chacaras/{id}/mensalidade")&&!str_contains($routes,"/proprietario/chacaras/{id}/mensalidade"));
$check('12 controller exige admin',str_contains($read('app/controllers/MensalidadeAnuncioController.php'),"requireRole('admin')"));
$check('13 assinatura idempotente por referencia externa',str_contains($monthly,'listarAssinaturas')&&str_contains($migration,'asaas_subscription_id VARCHAR(100) UNIQUE'));
$check('14 webhook e cobranca idempotentes',str_contains($migration,'asaas_payment_id VARCHAR(100) NOT NULL UNIQUE')&&str_contains($migration,'asaas_event_id VARCHAR(120) UNIQUE'));
$check('15 historico preserva snapshots de valor',str_contains($migration,'historico_mensalidades_anuncios')&&str_contains($migration,'valor_centavos BIGINT'));
$check('16 nenhuma DDL permanece em models',!preg_match('/\b(?:CREATE TABLE|ALTER TABLE|CREATE INDEX)\b/i',$chacara.$owner));
$fake=new FakeAsaasClient();$sub=$fake->criarAssinatura(['externalReference'=>'mensalidade_chacara_1','value'=>'49.90','nextDueDate'=>'2099-01-01']);
$check('17 fake cria assinatura sem criar cobranca de reserva',$fake->assinaturasCriadas===1&&$fake->cobrancasCriadas===0&&$sub['id']==='sub_fake');
$check('18 migration sem operacao destrutiva',!preg_match('/\b(?:DROP TABLE|TRUNCATE)\b/i',$migration));
$base=['status_aprovacao'=>'aprovada','status_operacional'=>'disponivel','proprietario_status'=>'pendente','usuario_status'=>'ativo'];
$check('19 politica sem mensalidade permite anuncio',Chacara::elegivelPublicamente($base,null));
$check('20 politica com mensalidade em dia permite anuncio',Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'EM_DIA']));
$check('21 politica com mensalidade atrasada oculta anuncio',!Chacara::elegivelPublicamente($base,['ativa'=>true,'status'=>'ATRASADA']));
$check('22 politica preserva privado sem alterar dados',isset($base['status_aprovacao'])&&!array_key_exists('excluida',$base));
$check('22a conta efetivamente bloqueada impede anuncio',!Chacara::elegivelPublicamente(array_replace($base,['usuario_status'=>'bloqueado']),['ativa'=>true,'status'=>'EM_DIA']));
$check('23 primeira cobranca usa cliente HTTP existente',str_contains($monthly,"listarCobrancas(['subscription'=>")&&!str_contains($monthly,'new GuzzleHttp'));
$check('24 tela exibe mensagem durante preparacao',str_contains($view,'primeira cobran&ccedil;a est&aacute; sendo preparada'));
$check('25 pagar redireciona para URL vinculada sem criar cobranca',str_contains($controller,"header('Location: '.\$url")&&!str_contains($controller,'criarCobranca'));
$check('26 consulta do proprietario fornece URL e vencimento',str_contains($owner,'cm.invoice_url')&&str_contains($owner,'cm.vencimento'));
$check('27 sincronizacao nao promove mensalidade para em dia',!preg_match('/sincronizarPrimeiraCobranca[\s\S]*?alterarStatus\([^)]*EM_DIA/i',$monthly));

$guardAllows=static function(string $environment,string $database,string $configuredDatabase,array $arguments):bool{
    try{MigrationExecutionGuard::assertAllowed($environment,$database,$configuredDatabase,$arguments);return true;}
    catch(RuntimeException){return false;}
};
$check('28 guard permite somente banco local conhecido em desenvolvimento',$guardAllows('development','alugfacil_dev','alugfacil_dev',[]));
$check('29 guard recusa banco desconhecido em desenvolvimento',!$guardAllows('development','outro_banco','outro_banco',[]));
$check('30 guard recusa producao sem confirmacao',!$guardAllows('production','alugfaciln_sistema','alugfaciln_sistema',[]));
$check('31 guard permite producao confirmada fora do banco dev',$guardAllows('production','alugfaciln_sistema','alugfaciln_sistema',['--confirm-production']));
$check('32 guard recusa alugfacil_dev em producao',!$guardAllows('production','alugfacil_dev','alugfacil_dev',['--confirm-production']));
$check('33 guard recusa banco real diferente do DB_NAME',!$guardAllows('production','banco_real','banco_configurado',['--confirm-production']));
$locationApply=$read('database/apply_location_migration.php');$monthlyApply=$read('database/apply_mensalidade_anuncio_migration.php');
$check('34 executores usam o guard compartilhado',str_contains($locationApply,'MigrationExecutionGuard::authorize')&&str_contains($monthlyApply,'MigrationExecutionGuard::authorize'));
$check('35 migration de localizacao segue incremental e sem drop',str_contains($read('database/location_migration.sql'),'ADD COLUMN IF NOT EXISTS')&&!preg_match('/\b(?:DROP|TRUNCATE)\b/i',$read('database/location_migration.sql')));

echo "Total: ".($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;exit($fail?1:0);
