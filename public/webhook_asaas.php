<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/helpers/functions.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $parts[0] = strtolower($parts[0]);
    $file = APP_ROOT . '/app/' . implode('/', $parts) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$config = require APP_ROOT . '/app/config/config.php';
date_default_timezone_set($config['timezone']);

use App\Helpers\AsaasHelper;

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

$rawPayload = file_get_contents('php://input') ?: '';
$payload = json_decode($rawPayload, true);

if (!is_array($payload)) {
    AsaasHelper::logErro('Webhook Asaas com JSON invalido.', ['payload' => $rawPayload]);
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

try {
    (new AsaasHelper())->tratarWebhook($payload);
} catch (Throwable $exception) {
    AsaasHelper::logErro('Falha no webhook Asaas: ' . $exception->getMessage(), $payload);
}

http_response_code(200);
echo json_encode(['ok' => true]);
