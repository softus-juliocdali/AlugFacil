<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Services\AsaasEnvironment;
use App\Services\AsaasHttpClient;

try {
    $config=AsaasEnvironment::assertCredentials();
    echo 'Asaas environment: '.strtoupper($config['environment']).PHP_EOL;
    echo 'Base URL: '.$config['base_url'].PHP_EOL;
    echo 'API key: configured'.PHP_EOL;
    (new AsaasHttpClient())->verificarContaRaiz();
    echo 'Authentication: OK'.PHP_EOL.'Connectivity: OK'.PHP_EOL;
    echo 'Production access: '.($config['environment']==='sandbox'?'BLOCKED':'ENABLED').PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,'Health check: FAILED - '.$e->getMessage().PHP_EOL);
    exit(1);
}
