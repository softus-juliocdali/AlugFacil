<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/scripts/bootstrap.php';
require __DIR__ . '/MigrationExecutionGuard.php';

use App\Core\Database;

$db = Database::getConnection();

try {
    $target = MigrationExecutionGuard::authorize($db, $argv, 'mensalidade_anuncio_migration.sql');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Execucao recusada: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}

$sql = file_get_contents(__DIR__ . '/mensalidade_anuncio_migration.sql');
if ($sql === false) {
    throw new RuntimeException('Migration de mensalidade do anuncio nao encontrada.');
}

$db->exec($sql);
echo 'Migration de mensalidade do anuncio aplicada em ' . $target['database'] . '.' . PHP_EOL;
