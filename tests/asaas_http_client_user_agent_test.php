<?php
declare(strict_types=1);

define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $class):void {
    $prefix='App\\';
    if(!str_starts_with($class,$prefix))return;
    $parts=explode('\\',substr($class,strlen($prefix)));
    $parts[0]=strtolower($parts[0]);
    require APP_ROOT.'/app/'.implode('/',$parts).'.php';
});

use App\Services\AsaasHttpClient;

$client=new AsaasHttpClient('https://api-sandbox.asaas.com/v3','test-token');
$method=new ReflectionMethod($client,'headers');
$headers=$method->invoke($client);
$source=file_get_contents(APP_ROOT.'/app/services/AsaasHttpClient.php');

$checks=[
    'user_agent_padrao_exato'=>in_array('User-Agent: AlugFacil/1.0',$headers,true),
    'user_agent_unico'=>count(array_filter($headers,static fn(string $header):bool=>str_starts_with(strtolower($header),'user-agent:')))===1,
    'access_token_preservado'=>in_array('access_token: test-token',$headers,true),
    'accept_preservado'=>in_array('accept: application/json',$headers,true),
    'content_type_preservado'=>in_array('content-type: application/json',$headers,true),
    'headers_aplicados_centralmente'=>str_contains((string)$source,'CURLOPT_HTTPHEADER=>$headers'),
    'ssl_verificacao_nao_desabilitada'=>!preg_match('/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*=>\s*false/',(string)$source),
];

$failures=0;
foreach($checks as $name=>$passed){
    echo ($passed?'[OK] ':'[FALHA] ').$name.PHP_EOL;
    if(!$passed)$failures++;
}
exit($failures===0?0:1);
