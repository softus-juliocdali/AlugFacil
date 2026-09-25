<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$load = require dirname(__DIR__, 2) . '/app/config/env.php';
$load(dirname(__DIR__, 2) . '/.env');
if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)
    || !in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true)
    || getenv('DB_NAME') !== 'alugfacil_dev') {
    fwrite(STDERR, "Backend mobile restrito ao banco local de desenvolvimento.\n");
    exit(1);
}
echo "[OK] Backend de desenvolvimento local; sem migrations ou seeds.\n";
