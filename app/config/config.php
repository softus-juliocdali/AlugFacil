<?php

declare(strict_types=1);

$loadEnvironment = require __DIR__ . '/env.php';
$loadEnvironment(dirname(__DIR__, 2) . '/.env');

$database = require __DIR__ . '/database.php';
$appEnv = (string) (getenv('APP_ENV') ?: 'production');
$rawAffiliateCookieSecret = trim((string) (getenv('AFFILIATE_COOKIE_SECRET') ?: ''));
if ($rawAffiliateCookieSecret === '' && !in_array($appEnv, ['development', 'test'], true)) {
    app_log('AFFILIATE_COOKIE_SECRET ausente. A atribuicao de afiliado exige segredo proprio em producao.');
    throw new RuntimeException('Configuracao obrigatoria de seguranca ausente.');
}
if ($rawAffiliateCookieSecret !== '' && strlen($rawAffiliateCookieSecret) < 32) {
    app_log('AFFILIATE_COOKIE_SECRET invalido. Configure pelo menos 32 caracteres aleatorios.');
    throw new RuntimeException('Configuracao obrigatoria de seguranca invalida.');
}
$affiliateCookieSecret = hash(
    'sha256',
    $rawAffiliateCookieSecret !== ''
        ? $rawAffiliateCookieSecret
        : 'development-only-affiliate-cookie-secret|' . dirname(__DIR__, 2)
);

return [
    'app_name' => 'Alug Fácil',
    'app_env' => $appEnv,
    'app_url' => rtrim(getenv('APP_URL') ?: 'https://alugfacil.net.br', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',
    'session_name' => 'alugfacil_session',
    'affiliate_cookie_secret' => $affiliateCookieSecret,
    'database' => $database,
];
