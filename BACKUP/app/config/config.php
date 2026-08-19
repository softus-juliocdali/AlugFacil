<?php

declare(strict_types=1);

$loadEnvironment = require __DIR__ . '/env.php';
$loadEnvironment(dirname(__DIR__, 2) . '/.env');

return [
    'app_name' => 'Alug Fácil',
    'app_env' => getenv('APP_ENV') ?: 'production',
    'app_url' => rtrim(getenv('APP_URL') ?: 'https://alugfacil.net.br', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',
    'session_name' => 'alugfacil_session',
    'database' => require __DIR__ . '/database.php',
];
