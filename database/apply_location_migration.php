<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/scripts/bootstrap.php';
require __DIR__ . '/MigrationExecutionGuard.php';

$db = App\Core\Database::getConnection();

try {
    $target = MigrationExecutionGuard::authorize($db, $argv, 'location_migration.sql');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Execucao recusada: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}

$sql = file_get_contents(__DIR__ . '/location_migration.sql');
if ($sql === false) {
    throw new RuntimeException('Migration de localizacao nao encontrada.');
}

$db->exec($sql);
echo 'Migration de localizacao aplicada em ' . $target['database'] . '.' . PHP_EOL;
