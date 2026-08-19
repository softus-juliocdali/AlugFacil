<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
$db=Database::getConnection();
if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Banco nao autorizado.');
$sql=file_get_contents(__DIR__.'/reservation_financial_refactor_migration.sql');
if($sql===false)throw new RuntimeException('Migration nao encontrada.');
$db->exec($sql);
echo "Migration financeira 07-R aplicada em alugfacil_dev.\n";
