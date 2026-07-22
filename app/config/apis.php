<?php

declare(strict_types=1);

if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');
}

if (!defined('ASAAS_API_KEY')) {
    define('ASAAS_API_KEY', getenv('ASAAS_API_KEY') ?: '');
}

if (!defined('ASAAS_BASE_URL')) {
    define('ASAAS_BASE_URL', getenv('ASAAS_BASE_URL') ?: 'https://sandbox.asaas.com/api/v3');
}

if (!defined('ASAAS_WEBHOOK_TOKEN')) {
    define('ASAAS_WEBHOOK_TOKEN', getenv('ASAAS_WEBHOOK_TOKEN') ?: '');
}

return [
    'maps' => [
        'provider' => 'google',
        'api_key' => GOOGLE_MAPS_API_KEY,
    ],
    'asaas' => [
        'api_key' => ASAAS_API_KEY,
        'base_url' => rtrim(ASAAS_BASE_URL, '/'),
        'webhook_token' => ASAAS_WEBHOOK_TOKEN,
        'webhook_max_body_bytes' => max(1024, (int) (getenv('ASAAS_WEBHOOK_MAX_BODY_BYTES') ?: 1048576)),
        'webhook_process_limit' => max(1, min(500, (int) (getenv('ASAAS_WEBHOOK_PROCESS_LIMIT') ?: 50))),
    ],
    'payment' => [
        'provider' => getenv('PAYMENT_PROVIDER') ?: '',
        'public_key' => getenv('PAYMENT_PUBLIC_KEY') ?: '',
        'access_token' => getenv('PAYMENT_ACCESS_TOKEN') ?: '',
    ],
];
