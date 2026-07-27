<?php
declare(strict_types=1);
namespace App\Services;
interface AsaasPaymentClientInterface
{
 public function listarClientes(array $filtros):array;
 public function criarCliente(array $payload):array;
 public function listarCobrancas(array $filtros):array;
 public function criarCobranca(array $payload):array;
 public function consultarCobranca(string $paymentId):array;
}
