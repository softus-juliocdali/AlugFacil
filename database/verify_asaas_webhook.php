<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/scripts/bootstrap.php';
$db = App\Core\Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') throw new RuntimeException('Banco nao permitido.');
$columns = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='asaas_webhook_eventos' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
$indexes = $db->query("SELECT indexname FROM pg_indexes WHERE schemaname='public' AND tablename='asaas_webhook_eventos' ORDER BY indexname")->fetchAll(PDO::FETCH_COLUMN);
if (count($columns) < 19 || !in_array('idx_asaas_webhook_fila', $indexes, true)) throw new RuntimeException('Estrutura incompleta.');
echo 'Banco: alugfacil_dev' . PHP_EOL . 'Colunas: ' . implode(', ', $columns) . PHP_EOL . 'Indices: ' . implode(', ', $indexes) . PHP_EOL . "Verificacao aprovada.\n";
