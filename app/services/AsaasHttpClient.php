<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class AsaasHttpClient implements AsaasClientInterface,AsaasPaymentClientInterface
{
 private const USER_AGENT='AlugFacil/1.0';
 private string $environment;
 public function __construct(private ?string $baseUrl=null,private ?string $apiKey=null)
 {
  if($baseUrl!==null||$apiKey!==null){$this->baseUrl=rtrim((string)$baseUrl,'/');$this->apiKey=(string)$apiKey;$this->environment=str_contains($this->baseUrl,'api-sandbox.asaas.com')?'sandbox':'production';}
  else{$c=AsaasEnvironment::assertCredentials();$this->baseUrl=$c['base_url'];$this->apiKey=$c['api_key'];$this->environment=$c['environment'];}
 }
 public function criarSubconta(array $payload):array{return $this->request('POST','/accounts',$payload);}
 public function listarSubcontas(array $filtros):array{return $this->request('GET','/accounts',[],$filtros);}
 public function consultarSubconta(string $id):array{return $this->request('GET','/accounts/'.rawurlencode($id));}
 public function verificarContaRaiz():array{return $this->request('GET','/myAccount');}
 public function listarClientes(array$f):array{return$this->request('GET','/customers',[],$f);}
 public function criarCliente(array$p):array{return$this->request('POST','/customers',$p);}
 public function listarCobrancas(array$f):array{return$this->request('GET','/payments',[],$f);}
 public function criarCobranca(array$p):array{return$this->request('POST','/payments',$p);}
 public function consultarCobranca(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id));}
 public function consultarLinhaDigitavel(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id).'/identificationField');}
 public function consultarQrCodePix(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id).'/pixQrCode');}
 public function listarAssinaturas(array$f):array{return$this->request('GET','/subscriptions',[],$f);}
 public function criarAssinatura(array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('POST','/subscriptions',$p);}
 public function atualizarAssinatura(string$id,array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('PUT','/subscriptions/'.rawurlencode($id),$p);}
 public function cancelarAssinatura(string$id):array{AsaasEnvironment::assertCredentials(true);return$this->request('DELETE','/subscriptions/'.rawurlencode($id));}
 public function listarWebhooks():array{return$this->request('GET','/webhooks');}
 public function criarWebhook(array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('POST','/webhooks',$p);}
 public function atualizarWebhook(string$id,array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('PUT','/webhooks/'.rawurlencode($id),$p);}
 private function request(string $method,string $path,array $payload=[],array $query=[]):array
 {
  if($this->apiKey===''||$this->baseUrl==='')throw new RuntimeException('Credenciais Asaas nao configuradas.');
  $url=$this->baseUrl.$path.($query?'?'.http_build_query($query):'');$ch=curl_init($url);$headers=$this->headers();
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
  if($method!=='GET'&&$payload!==[])curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
  $body=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  if($body===false||$errno!==0)throw new AsaasTimeoutException('Falha local de comunicacao com o Asaas; resultado externo desconhecido.');
  $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('Resposta Asaas invalida.');
  unset($data['apiKey']);
  if($status<200||$status>=300)throw new AsaasApiException(self::sanitizarErro($data),$status);
  return $data;
 }
 private function headers():array{return['accept: application/json','content-type: application/json','access_token: '.$this->apiKey,'User-Agent: '.self::USER_AGENT];}
 private static function sanitizarErro(array $data):string{$messages=[];foreach(($data['errors']??[])as$e){if(is_array($e))$messages[]=mb_substr((string)($e['description']??$e['code']??''),0,200);}return $messages?implode('; ',$messages):'Asaas recusou a solicitacao.';}
}
