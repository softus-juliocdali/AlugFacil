<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/scripts/bootstrap.php';

$db = App\Core\Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Migration de localizacao permitida somente em alugfacil_dev.');
}

$sql = file_get_contents(__DIR__ . '/location_migration.sql');
if ($sql === false) {
    throw new RuntimeException('Migration de localizacao nao encontrada.');
}

$db->exec($sql);
echo "Migration de localizacao aplicada em alugfacil_dev.\n";
