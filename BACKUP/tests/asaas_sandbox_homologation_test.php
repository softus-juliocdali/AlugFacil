<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));require APP_ROOT.'/app/helpers/functions.php';
spl_autoload_register(static function(string$c):void{$p='App\\';if(!str_starts_with($c,$p))return;$x=explode('\\',substr($c,4));$x[0]=strtolower($x[0]);require APP_ROOT.'/app/'.implode('/',$x).'.php';});
use App\Services\AsaasEnvironment;
$ok=0;$fail=0;$check=function(bool$c,string$n)use(&$ok,&$fail){echo($c?'[OK] ':'[FAIL] ').$n.PHP_EOL;$c?$ok++:$fail++;};
$old=[];foreach(['APP_ENV','ASAAS_ENVIRONMENT','ASAAS_BASE_URL','ASAAS_API_KEY','ASAAS_ALLOW_SANDBOX_MUTATIONS']as$k)$old[$k]=getenv($k);
putenv('APP_ENV=development');putenv('ASAAS_ENVIRONMENT=sandbox');putenv('ASAAS_BASE_URL='.AsaasEnvironment::SANDBOX_URL);putenv('ASAAS_API_KEY=$aact_fake_sandbox_key');putenv('ASAAS_ALLOW_SANDBOX_MUTATIONS=false');
$check(AsaasEnvironment::config()['environment']==='sandbox','sandbox permitido');
try{putenv('ASAAS_ENVIRONMENT=production');putenv('ASAAS_BASE_URL='.AsaasEnvironment::PRODUCTION_URL);AsaasEnvironment::config();$check(false,'production bloqueada');}catch(Throwable){$check(true,'production bloqueada');}
putenv('ASAAS_ENVIRONMENT=sandbox');putenv('ASAAS_BASE_URL='.AsaasEnvironment::SANDBOX_URL);
try{putenv('ASAAS_API_KEY=');AsaasEnvironment::assertCredentials();$check(false,'segredo ausente falha');}catch(Throwable){$check(true,'segredo ausente falha');}
$controller=file_get_contents(APP_ROOT.'/app/controllers/ReservaController.php');$helper=file_get_contents(APP_ROOT.'/app/helpers/AsaasHelper.php');$webhook=file_get_contents(APP_ROOT.'/public/webhook_asaas.php');
$check(str_contains($controller,"!=='PIX'"),'cartao e boleto rejeitados');$check(str_contains($controller,'quantidade_parcelas'),'parcelamento rejeitado');$check(str_contains($controller,'valor_total_cliente_centavos'),'valor vem do backend');
$check(str_contains($helper,"'billingType' => 'PIX'"),'payload PIX');$check(!str_contains($helper,"'splits' =>"),'payload sem split');$check(str_contains($helper,'externalReference'),'external reference');$check(str_contains($helper,'listarCobrancas'),'idempotencia de cobranca');
$check(str_contains($webhook,'asaas-access-token'),'token webhook');$check(str_contains($webhook,'hash_equals'),'comparacao segura');$check(str_contains(file_get_contents(APP_ROOT.'/app/models/AsaasWebhookEvento.php'),'ON CONFLICT (asaas_event_id)'),'webhook idempotente');
foreach($old as$k=>$v){$v===false?putenv($k):putenv($k.'='.$v);}
echo "TOTAL=".($ok+$fail)." FALHAS=$fail".PHP_EOL;exit($fail?1:0);
