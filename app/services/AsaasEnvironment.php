<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

final class AsaasEnvironment
{
    public const SANDBOX_URL = 'https://api-sandbox.asaas.com/v3';
    public const PRODUCTION_URL = 'https://api.asaas.com/v3';

    public static function config(): array
    {
        $environment = strtolower(trim((string) (getenv('ASAAS_ENVIRONMENT') ?: 'sandbox')));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('ASAAS_ENVIRONMENT deve ser sandbox ou production.');
        }
        $baseUrl = rtrim(trim((string) (getenv('ASAAS_BASE_URL') ?: ($environment === 'sandbox' ? self::SANDBOX_URL : self::PRODUCTION_URL))), '/');
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
        if ($environment !== 'sandbox') {
            throw new RuntimeException('Asaas Production nao foi liberado nesta fase.');
        }
        if ($baseUrl !== self::SANDBOX_URL) {
            throw new RuntimeException('ASAAS_BASE_URL nao corresponde ao ambiente selecionado.');
        }
        return ['environment' => $environment, 'base_url' => $baseUrl, 'app_env' => $appEnv];
    }

    public static function assertCredentials(bool $mutation = false): array
    {
        $config = self::config();
        $key = trim((string) (getenv('ASAAS_SANDBOX_API_KEY') ?: getenv('ASAAS_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('ASAAS_API_KEY nao configurada.');
        }
        if ($config['environment'] === 'sandbox' && !str_starts_with($key, '$aact_hmlg_')) {
            throw new RuntimeException('ASAAS_API_KEY nao possui o formato esperado.');
        }
        if ($mutation && $config['environment'] === 'sandbox' && filter_var(getenv('ASAAS_ALLOW_SANDBOX_MUTATIONS') ?: 'false', FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('Mutacoes Sandbox bloqueadas. Defina ASAAS_ALLOW_SANDBOX_MUTATIONS=true para homologacao.');
        }
        return $config + ['api_key' => $key];
    }
}
