<?php
declare(strict_types=1);
namespace App\Services;
final class FakeAsaasClient implements AsaasClientInterface
{
 public int $criacoes=0;public function __construct(private array $resposta=['id'=>'acc_fake','walletId'=>'wal_fake','apiKey'=>'secret-never-persist']){}
 public function criarSubconta(array $payload):array{$this->criacoes++;return $this->resposta;}
 public function listarSubcontas(array $filtros):array{return ['data'=>[],'totalCount'=>0];}
 public function consultarSubconta(string $accountId):array{return ['id'=>$accountId,'walletId'=>'wal_fake','status'=>'APPROVED'];}
 public function verificarContaRaiz():array{return ['id'=>'root_fake','personType'=>'JURIDICA','status'=>'ACTIVE'];}
}
