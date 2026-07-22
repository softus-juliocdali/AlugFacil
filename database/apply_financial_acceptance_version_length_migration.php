<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/scripts/bootstrap.php';

$db = App\Core\Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Migration permitida somente em alugfacil_dev.');
}

$sql = file_get_contents(__DIR__ . '/financial_acceptance_version_length_migration.sql');
if ($sql === false) {
    throw new RuntimeException('Migration corretiva do aceite financeiro nao encontrada.');
}

$db->beginTransaction();
try {
    $db->exec($sql);
    $db->commit();
    echo "Migration corretiva do aceite financeiro aplicada em alugfacil_dev.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
