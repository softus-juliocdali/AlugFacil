<?php
declare(strict_types=1);
use App\Services\AsaasPaymentClientInterface;use App\Services\AsaasTransferClientInterface;use App\Services\FinancialOperationService;
final class FinancialGatewayFake implements AsaasPaymentClientInterface,AsaasTransferClientInterface
{
 public array $payments=[],$customers=[],$transfers=[];public int $paymentPosts=0,$transferPosts=0,$refundPosts=0,$customerPosts=0;public string $balance='100000.00';public $afterPayment=null,$beforePayment=null;
 public function __construct(public string $scope){}
 public function accountScope():string{return $this->scope;}
 private function mutation():void{if(!FinancialOperationService::allowsMutation())throw new RuntimeException('Fake exige intencao duravel.');}
 public function listarClientes(array $f):array{return ['data'=>array_values(array_filter($this->customers,fn($x)=>$x['externalReference']===$f['externalReference'])),'hasMore'=>false];}
 public function criarCliente(array $p):array{$this->mutation();$this->customerPosts++;$p['id']='cus_'.$this->scope.'_'.$this->customerPosts;$this->customers[$p['id']]=$p;return $p;}
 public function listarCobrancas(array $f):array{return ['data'=>array_values(array_filter($this->payments,fn($x)=>($x['externalReference']??'')===($f['externalReference']??''))),'hasMore'=>false];}
 public function criarCobranca(array $p):array{$this->mutation();$this->paymentPosts++;if($this->beforePayment)($this->beforePayment)($p);$p+=['id'=>'pay_'.$this->scope.'_'.$this->paymentPosts,'status'=>'PENDING','invoiceUrl'=>'https://sandbox.asaas.com/i/test-only'];$this->payments[$p['id']]=$p;if($this->afterPayment)($this->afterPayment)($p);return $p;}
 public function consultarCobranca(string $id):array{return $this->payments[$id];}
 public ?array $refundHistory=null;
 public int $refundPageSize=100;
 public function listarEstornosCobranca(string $id,array $query=[]):array{$items=$this->refundHistory??($this->payments[$id]['refunds']??[]);$offset=(int)($query['offset']??0);$limit=min($this->refundPageSize,(int)($query['limit']??100));return ['data'=>array_slice($items,$offset,$limit),'hasMore'=>$offset+$limit<count($items)];}
 public function consultarQrCodePix(string $id):array{return [];}
 public function consultarSaldo():array{return ['balance'=>$this->balance];}
 public function listarTransferencias(array $f):array{return ['data'=>array_values(array_filter($this->transfers,fn($x)=>$x['externalReference']===$f['externalReference'])),'hasMore'=>false];}
 public function criarTransferencia(array $p):array{$this->mutation();$this->transferPosts++;$p+=['id'=>'tr_'.$this->scope.'_'.$this->transferPosts,'status'=>'DONE'];$this->transfers[$p['id']]=$p;return $p;}
 public function consultarTransferencia(string $id):array{return $this->transfers[$id];}
 public function cancelarCobranca(string $id):array{$this->mutation();$this->payments[$id]['deleted']=true;return $this->payments[$id];}
 public function estornarCobranca(string $id,array $p):array{$this->mutation();$this->refundPosts++;$this->payments[$id]['refunds'][]=$p+['status'=>'PENDING','dateCreated'=>date('Y-m-d H:i:s')];return $this->payments[$id];}
 public function listarAssinaturas(array $f):array{return ['data'=>[]];}
 public function criarAssinatura(array $p):array{throw new RuntimeException('Nao exercitado');}
 public function atualizarAssinatura(string $id,array $p):array{throw new RuntimeException('Nao exercitado');}
 public function cancelarAssinatura(string $id):array{throw new RuntimeException('Nao exercitado');}
 public function listarWebhooks():array{return ['data'=>[]];}
 public function criarWebhook(array $p):array{throw new RuntimeException('Nao exercitado');}
 public function atualizarWebhook(string $id,array $p):array{throw new RuntimeException('Nao exercitado');}
}
