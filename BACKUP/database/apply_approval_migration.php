<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $parts[0] = strtolower($parts[0]);
    $file = APP_ROOT . '/app/' . implode('/', $parts) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$sql = file_get_contents(__DIR__ . '/approval_workflow_migration.sql');
if ($sql === false) {
    throw new RuntimeException('Migration nao encontrada.');
}

$db = App\Core\Database::getConnection();
$db->exec($sql);
echo "Migration de aprovacao aplicada com sucesso.\n";
