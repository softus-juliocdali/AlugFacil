<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Services\AsaasEnvironment;
use App\Services\AsaasHttpClient;

try {
    $config=AsaasEnvironment::assertCredentials(true);
    if($config['environment']!=='sandbox')throw new RuntimeException('Provisionamento permitido somente no Sandbox.');
    $url=trim((string)(getenv('ASAAS_WEBHOOK_URL')?:''));$token=trim((string)(getenv('ASAAS_WEBHOOK_TOKEN')?:''));
    if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('ASAAS_WEBHOOK_URL deve ser uma URL HTTPS publica.');
    if(in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['localhost','127.0.0.1'],true))throw new RuntimeException('ASAAS_WEBHOOK_URL nao pode apontar para localhost.');
    if(strlen($token)<32||strlen($token)>255||preg_match('/\s/',$token)||hash_equals(trim((string)getenv('ASAAS_API_KEY')),$token))throw new RuntimeException('ASAAS_WEBHOOK_TOKEN deve ser unico, sem espacos e possuir 32 a 255 caracteres.');
    $events=['PAYMENT_CREATED','PAYMENT_UPDATED','PAYMENT_CONFIRMED','PAYMENT_RECEIVED','PAYMENT_OVERDUE','PAYMENT_DELETED','PAYMENT_REFUNDED','PAYMENT_CHARGEBACK_REQUESTED'];
    $payload=['name'=>'Alug Facil Sandbox','url'=>$url,'emailEnabledForInterruptedQueue'=>true,'enabled'=>true,'interrupted'=>false,'authToken'=>$token,'events'=>$events];
    $client=new AsaasHttpClient();$list=$client->listarWebhooks();$match=null;
    foreach(($list['data']??[])as$item)if(is_array($item)&&(($item['name']??'')==='Alug Facil Sandbox'||($item['url']??'')===$url)){$match=$item;break;}
    $result=$match?$client->atualizarWebhook((string)$match['id'],$payload):$client->criarWebhook($payload);
    echo 'Environment: sandbox'.PHP_EOL.'Webhook URL: '.$url.PHP_EOL.'Webhook token: configured'.PHP_EOL.'API key: configured'.PHP_EOL.'Webhook ID: '.($result['id']??'unknown').PHP_EOL.'Status: enabled'.PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,'Provisionamento: FAILED - '.$e->getMessage().PHP_EOL);exit(1); }
