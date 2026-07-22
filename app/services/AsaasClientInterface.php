<?php
declare(strict_types=1);
namespace App\Services;
interface AsaasClientInterface
{
    public function criarSubconta(array $payload): array;
    public function listarSubcontas(array $filtros): array;
    public function consultarSubconta(string $accountId): array;
    public function verificarContaRaiz(): array;
}
