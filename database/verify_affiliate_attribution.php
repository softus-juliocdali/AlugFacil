<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;

$db = Database::getConnection();
$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$columns = $db->query(
    "SELECT column_name, is_nullable
     FROM information_schema.columns
     WHERE table_schema='public' AND table_name='proprietarios'
       AND column_name IN ('afiliado_id','afiliado_origem','afiliado_atribuido_em')"
)->fetchAll();
$check('tres colunas de atribuicao existem', count($columns) === 3);
$check('relacionamento permanece opcional', count(array_filter($columns, static fn (array $column): bool => $column['is_nullable'] === 'YES')) === 3);

$constraints = $db->query(
    "SELECT conname, contype, pg_get_constraintdef(oid) AS definition
     FROM pg_constraint
     WHERE conrelid='proprietarios'::REGCLASS
       AND conname IN ('fk_proprietarios_afiliado','chk_proprietarios_atribuicao_afiliado')"
)->fetchAll();
$definitions = implode("\n", array_column($constraints, 'definition'));
$check('fk aponta para afiliados com delete restrict', str_contains($definitions, 'REFERENCES afiliados(id)') && str_contains($definitions, 'ON DELETE RESTRICT'));
$check('origem limitada a link ou codigo', str_contains($definitions, "'link'") && str_contains($definitions, "'codigo'"));

$index = $db->query(
    "SELECT indexdef FROM pg_indexes
     WHERE schemaname='public' AND tablename='proprietarios' AND indexname='idx_proprietarios_afiliado'"
)->fetchColumn();
$check('indice de consulta por afiliado existe', is_string($index) && str_contains($index, '(afiliado_id)'));
$check('nao existem atribuicoes parciais', !(bool) $db->query(
    "SELECT EXISTS(
        SELECT 1 FROM proprietarios
        WHERE (afiliado_id IS NULL) <> (afiliado_origem IS NULL)
           OR (afiliado_id IS NULL) <> (afiliado_atribuido_em IS NULL)
    )"
)->fetchColumn());

echo 'Total: ' . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
