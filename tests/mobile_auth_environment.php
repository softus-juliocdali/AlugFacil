<?php
declare(strict_types=1);
require __DIR__ . '/support/PublicApiTest.php';
$config = require APP_ROOT . '/app/config/config.php';
$c = $config['database'];
foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME'] as $key) {
    echo $key . '=' . $c[$key] . PHP_EOL;
}
echo 'APP_ENV=' . $config['app_env'] . PHP_EOL;
apiCheck($c['DB_DRIVER'] === 'pgsql' && $c['DB_HOST'] === '127.0.0.1'
    && (string) $c['DB_PORT'] === '5432' && $c['DB_NAME'] === 'alugfacil_dev'
    && $config['app_env'] === 'development', 'Expected local environment');
$db = App\Core\Database::getConnection();
echo json_encode($db->query('SELECT current_database() AS db, inet_server_addr() AS host, inet_server_port() AS port')->fetch()) . PHP_EOL;
echo json_encode($db->query("SELECT column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name='usuarios'")->fetchAll()) . PHP_EOL;
