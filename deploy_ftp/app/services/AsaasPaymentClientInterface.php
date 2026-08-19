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
 public function consultarQrCodePix(string $paymentId):array;
 public function listarAssinaturas(array $filtros):array;
 public function criarAssinatura(array $payload):array;
 public function atualizarAssinatura(string $subscriptionId,array $payload):array;
 public function cancelarAssinatura(string $subscriptionId):array;
 public function listarWebhooks():array;
 public function criarWebhook(array $payload):array;
 public function atualizarWebhook(string $webhookId,array $payload):array;
}
