<?php
declare(strict_types=1);
$load=require dirname(__DIR__).'/app/config/env.php';$load(dirname(__DIR__).'/.env');
$base=rtrim((string)getenv('ASAAS_BASE_URL'),'/');$key=(string)getenv('ASAAS_API_KEY');
if(getenv('ASAAS_ENVIRONMENT')!=='sandbox'||$base!=='https://api-sandbox.asaas.com/v3')throw new RuntimeException('Preflight limitado ao host oficial Sandbox.');
if(!str_starts_with($key,'$aact_hmlg_'))throw new RuntimeException('Chave nao identificada explicitamente como Sandbox; nenhuma requisicao enviada.');
if(!in_array('--remote-read',$argv,true)){echo "Configuracao Sandbox identificada. Use --remote-read para GET de dados comerciais.\n";exit;}
$curl=curl_init($base.'/myAccount/commercialInfo');
curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['access_token: '.$key,'User-Agent: AlugFacil/1.0','accept: application/json'],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false]);
$body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_errno($curl);
$data=is_string($body)?json_decode($body,true):null;
$result=['environment'=>'sandbox','operation'=>'GET commercialInfo','http_status'=>$status,'transport_error'=>$error];
if($status===200&&is_array($data)){
    $result['account_type']=strlen(preg_replace('/\D/','',(string)($data['cpfCnpj']??'')))===14?'PJ':(strlen(preg_replace('/\D/','',(string)($data['cpfCnpj']??'')))===11?'PF':'nao_informado');
    $result['status']=$data['status']??null;
}else $result['result']='Nao foi possivel validar credenciais/capacidade da conta. Nenhum dado pessoal ou segredo exibido.';
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($status===200?0:1);
