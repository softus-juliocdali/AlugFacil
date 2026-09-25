<?php
declare(strict_types=1);
$load=require dirname(__DIR__).'/app/config/env.php';$load(dirname(__DIR__).'/.env');
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true)||getenv('ASAAS_ENVIRONMENT')!=='sandbox')throw new RuntimeException('Somente ambiente local Sandbox.');
if(!extension_loaded('openssl'))throw new RuntimeException('Extensao OpenSSL obrigatoria.');
if(getenv('ASAAS_SUBACCOUNT_ENCRYPTION_KEY')){echo "Cofre ja configurado; chave preservada.\n";exit;}
$file=dirname(__DIR__).'/.env';$handle=fopen($file,'a+');if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Nao foi possivel preparar cofre.');
rewind($handle);$contents=stream_get_contents($handle);
if(preg_match('/^ASAAS_SUBACCOUNT_ENCRYPTION_KEY=/m',$contents))throw new RuntimeException('Configuracao de cofre existente requer revisao.');
if(fwrite($handle,PHP_EOL.'ASAAS_SUBACCOUNT_ENCRYPTION_KEY='.bin2hex(random_bytes(32)).PHP_EOL)===false)throw new RuntimeException('Falha ao gravar chave local.');
flock($handle,LOCK_UN);fclose($handle);echo "Chave do cofre gerada no .env local; valor nao exibido. Preserve backup seguro do .env para restaurar o cofre.\n";
