<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class AsaasHttpClient implements AsaasClientInterface,AsaasPaymentClientInterface,AsaasTransferClientInterface
{
 private const USER_AGENT='AlugFacil/1.0';
 private string $environment;
 private ?string $scope = null;
 public function __construct(private ?string $baseUrl=null,private ?string $apiKey=null)
 {
  if($baseUrl!==null||$apiKey!==null){$this->baseUrl=rtrim((string)$baseUrl,'/');if($this->baseUrl!==AsaasEnvironment::SANDBOX_URL)throw new RuntimeException('Host Asaas nao permitido.');$this->apiKey=(string)$apiKey;$this->environment='sandbox';}
  else{$c=AsaasEnvironment::assertCredentials();$this->baseUrl=$c['base_url'];$this->apiKey=$c['api_key'];$this->environment=$c['environment'];}
 }
 public function criarSubconta(array $payload):array{AsaasSubaccountVault::ready();$scope=$this->accountScope();$r=$this->request('POST','/accounts',$payload);if(empty($r['id'])||empty($r['apiKey']))throw new AsaasTimeoutException('Resposta de subconta incompleta; concilie antes de repetir.');AsaasSubaccountVault::save($scope,(string)$r['id'],(string)$r['apiKey']);unset($r['apiKey']);return $r;}
 public function consultarStatusSubconta(string $id):array{$key=AsaasSubaccountVault::load($this->accountScope(),$id);$client=new self(AsaasEnvironment::SANDBOX_URL,$key);return $client->request('GET','/myAccount/status');}
 public function listarSubcontas(array $filtros):array{return $this->request('GET','/accounts',[],$filtros);}
 public function consultarSubconta(string $id):array{return $this->request('GET','/accounts/'.rawurlencode($id));}
 public function verificarContaRaiz():array{return $this->request('GET','/myAccount/commercialInfo');}
 public function accountScope():string {if($this->scope!==null)return $this->scope;$r=$this->request('GET','/wallets');$rows=$r['data']??[];if(count($rows)!==1||empty($rows[0]['id']))throw new RuntimeException('Wallet da conta raiz nao identificada inequivocamente.');return $this->scope=(string)$rows[0]['id'];}
 public function listarClientes(array$f):array{return$this->request('GET','/customers',[],$f);}
 public function criarCliente(array$p):array{return$this->request('POST','/customers',$p);}
 public function listarCobrancas(array$f):array{return$this->request('GET','/payments',[],$f);}
 public function criarCobranca(array$p):array{return$this->request('POST','/payments',$p);}
 public function consultarCobranca(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id));}
 public function pagarCartaoSandbox(string $id,#[\SensitiveParameter] array $payload):array
 {if(PHP_SAPI!=='cli'||getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Homologacao de cartao exige CLI local.');return $this->request('POST','/payments/'.rawurlencode($id).'/payWithCreditCard',$payload);}
 public function pagarCobrancaCartao(string $id,#[\SensitiveParameter] array $payload):array
 {return $this->request('POST','/payments/'.rawurlencode($id).'/payWithCreditCard',$payload);}
 public function consultarLinhaDigitavel(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id).'/identificationField');}
 public function consultarQrCodePix(string$id):array{return$this->request('GET','/payments/'.rawurlencode($id).'/pixQrCode');}
 public function cancelarCobranca(string $id):array{return $this->request('DELETE','/payments/'.rawurlencode($id));}
 public function estornarCobranca(string $id,array $payload):array{return $this->request('POST','/payments/'.rawurlencode($id).'/refund',$payload);}
 public function listarEstornosCobranca(string $id,array $query=[]):array{return $this->request('GET','/payments/'.rawurlencode($id).'/refunds',[],$query);}
 public function consultarSaldo():array{return $this->request('GET','/finance/balance');}
 public function listarTransferencias(array $f):array{return $this->request('GET','/transfers',[],$f);}
 public function criarTransferencia(array $p):array{return $this->request('POST','/transfers',$p);}
 public function consultarTransferencia(string $id):array{return $this->request('GET','/transfers/'.rawurlencode($id));}
 public function simularConfirmacaoSandbox(string $id):array{if(PHP_SAPI!=='cli'||getenv('DB_NAME')!=='alugfacil_dev'||!FinancialOperationService::allowsMutation())throw new RuntimeException('Simulacao exige CLI local e intencao duravel.');return $this->request('POST','/sandbox/payment/'.rawurlencode($id).'/confirm');}
 public function listarAssinaturas(array$f):array{return$this->request('GET','/subscriptions',[],$f);}
 public function criarAssinatura(array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('POST','/subscriptions',$p);}
 public function atualizarAssinatura(string$id,array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('PUT','/subscriptions/'.rawurlencode($id),$p);}
 public function cancelarAssinatura(string$id):array{AsaasEnvironment::assertCredentials(true);return$this->request('DELETE','/subscriptions/'.rawurlencode($id));}
 public function listarWebhooks():array{return$this->request('GET','/webhooks');}
 public function criarWebhook(array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('POST','/webhooks',$p);}
 public function atualizarWebhook(string$id,array$p):array{AsaasEnvironment::assertCredentials(true);return$this->request('PUT','/webhooks/'.rawurlencode($id),$p);}
 private function request(string $method,string $path,#[\SensitiveParameter] array $payload=[],array $query=[]):array
 {
  if($this->apiKey===''||$this->baseUrl==='')throw new RuntimeException('Credenciais Asaas nao configuradas.');
  $config=AsaasEnvironment::config();
  if($config['environment']!=='sandbox'||$this->baseUrl!==AsaasEnvironment::SANDBOX_URL||!str_starts_with((string)$this->apiKey,'$aact_hmlg_'))throw new RuntimeException('Somente credenciais e host Sandbox sao permitidos.');
  if($method!=='GET')AsaasEnvironment::assertCredentials(true);
  if($method!=='GET')FinancialReleasePolicy::assertExternalMutationAllowed($method,$path,$payload);
  if($method!=='GET'&&preg_match('~^/(customers|accounts|payments|subscriptions|checkouts|transfers|escrow)(/|$)~',$path)&&!FinancialOperationService::allowsMutation())throw new RuntimeException('Mutacao financeira exige intencao duravel.');
  $url=$this->baseUrl.$path.($query?'?'.http_build_query($query):'');$ch=curl_init($url);$headers=$this->headers();
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>self::requestTimeout($payload)]);
  if($method!=='GET'&&$payload!==[])curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
  $body=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  if($body===false||$errno!==0)throw new AsaasTimeoutException('Falha local de comunicacao com o Asaas; resultado externo desconhecido.');
  $data=json_decode($body,true);if(!is_array($data))throw new AsaasTimeoutException('Resposta Asaas invalida; resultado desconhecido.');
  if(!($method==='POST'&&$path==='/accounts'))unset($data['apiKey']);
  if($status<200||$status>=300){$code=(string)($data['errors'][0]['code']??'unspecified');if(!preg_match('/^[a-zA-Z_]{1,60}$/D',$code))$code='unspecified';throw new AsaasApiException(self::sanitizarErro($data),$status,$code);}
  return $data+['_http_status'=>$status];
 }
 private function headers():array{return['accept: application/json','content-type: application/json','access_token: '.$this->apiKey,'User-Agent: '.self::USER_AGENT];}
 private static function requestTimeout(#[\SensitiveParameter] array $payload):int{return isset($payload['creditCardToken'])||isset($payload['creditCard'])?60:30;}
 private static function sanitizarErro(array $data):string{return 'Asaas recusou a solicitacao; consulte o codigo HTTP da operacao.';}
}
