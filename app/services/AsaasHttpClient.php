<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class AsaasHttpClient implements AsaasClientInterface,AsaasPaymentClientInterface
{
 public function __construct(private ?string $baseUrl=null,private ?string $apiKey=null){$this->baseUrl=rtrim($baseUrl??(getenv('ASAAS_BASE_URL')?:''),'/');$this->apiKey=$apiKey??(getenv('ASAAS_API_KEY')?:'');}
 public function criarSubconta(array $payload):array{return $this->request('POST','/accounts',$payload);}
 public function listarSubcontas(array $filtros):array{return $this->request('GET','/accounts',[],$filtros);}
 public function consultarSubconta(string $id):array{return $this->request('GET','/accounts/'.rawurlencode($id));}
 public function verificarContaRaiz():array{return $this->request('GET','/myAccount');}
 public function listarClientes(array$f):array{return$this->request('GET','/customers',[],$f);}
 public function criarCliente(array$p):array{return$this->request('POST','/customers',$p);}
 public function listarCobrancas(array$f):array{return$this->request('GET','/payments',[],$f);}
 public function criarCobranca(array$p):array{return$this->request('POST','/payments',$p);}
 public function consultarCobranca(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id));}
 private function request(string $method,string $path,array $payload=[],array $query=[]):array
 {
  if($this->apiKey===''||$this->baseUrl==='')throw new RuntimeException('Credenciais Asaas nao configuradas.');
  $url=$this->baseUrl.$path.($query?'?'.http_build_query($query):'');$ch=curl_init($url);$headers=['accept: application/json','content-type: application/json','access_token: '.$this->apiKey];
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
  if($method!=='GET')curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
  $body=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($body===false||$errno!==0)throw new AsaasTimeoutException('Resultado externo desconhecido apos falha de comunicacao.');
  $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('Resposta Asaas invalida.');
  unset($data['apiKey']);
  if($status<200||$status>=300)throw new AsaasApiException(self::sanitizarErro($data),$status);
  return $data;
 }
 private static function sanitizarErro(array $data):string{$messages=[];foreach(($data['errors']??[])as$e){if(is_array($e))$messages[]=mb_substr((string)($e['description']??$e['code']??''),0,200);}return $messages?implode('; ',$messages):'Asaas recusou a solicitacao.';}
}
