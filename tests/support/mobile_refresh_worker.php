<?php
declare(strict_types=1);
require __DIR__ . '/PublicApiTest.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$config = require APP_ROOT . '/app/config/config.php';
if ($config['database']['DB_NAME'] !== 'alugfacil_dev' || $config['database']['DB_HOST'] !== '127.0.0.1'
    || !preg_match('/^mobile_auth_test_[a-f0-9]{16}$/D', $input['schema'])) exit(2);
$db = App\Core\Database::getConnection();
$db->exec('SET search_path TO ' . $input['schema'] . ', pg_catalog');
while (microtime(true) < $input['start']) usleep(1000);
try { (new App\Api\MobileSessions($db))->refresh($input['token']); echo '200'; }
catch (App\Api\ApiException $e) { echo $e->status; }
