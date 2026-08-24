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

$relations = $db->query(
    "SELECT TO_REGCLASS('public.afiliados') AS afiliados,
            TO_REGCLASS('public.afiliados_codigo_seq') AS codigo_seq,
            TO_REGCLASS('public.configuracoes_comissao_afiliados') AS configuracao"
)->fetch();
$check('tabela de afiliados existe', !empty($relations['afiliados']));
$check('sequence de codigo existe', !empty($relations['codigo_seq']));
$check('configuracao global existe', !empty($relations['configuracao']));

$columnsStatement = $db->prepare(
    "SELECT column_name, data_type, column_default
     FROM information_schema.columns WHERE table_schema='public' AND table_name=:table"
);
$columnsStatement->execute(['table' => 'afiliados']);
$columns = [];
foreach ($columnsStatement->fetchAll() as $column) {
    $columns[$column['column_name']] = $column;
}
$required = ['id','codigo','nome','cpf_cnpj','telefone','email','senha_hash','chave_pix','tipo_chave_pix','banco','observacoes','status','criado_em','atualizado_em'];
$check('campos cadastrais e financeiros completos', array_diff($required, array_keys($columns)) === []);
$check('codigo usa nextval no default', str_contains((string) ($columns['codigo']['column_default'] ?? ''), 'nextval'));
$check('codigo usa formatador sem limite de quatro digitos',
    str_contains((string) ($columns['codigo']['column_default'] ?? ''), 'formatar_codigo_afiliado')
    && $db->query("SELECT formatar_codigo_afiliado(9999) = 'AF9999' AND formatar_codigo_afiliado(10000) = 'AF10000'")->fetchColumn()
);
$check('senha armazenada somente como hash', isset($columns['senha_hash']) && !isset($columns['senha']));

$indexes = (string) $db->query(
    "SELECT STRING_AGG(indexdef, E'\n') FROM pg_indexes WHERE schemaname='public' AND tablename='afiliados'"
)->fetchColumn();
$check('codigo e documento possuem unicidade', str_contains($indexes, '(codigo)') && str_contains($indexes, '(cpf_cnpj)'));
$check('e-mail possui unicidade case-insensitive', str_contains(strtolower($indexes), 'unique') && str_contains(strtolower($indexes), 'lower'));

$config = $db->query('SELECT id, percentual_bps FROM configuracoes_comissao_afiliados WHERE id=1')->fetch();
$check('configuracao singleton inicializada', $config && (int) $config['id'] === 1);
$check('percentual usa inteiro dentro dos limites', $config && (int) $config['percentual_bps'] > 0 && (int) $config['percentual_bps'] <= 10000);
$check('afiliado nao possui percentual individual', !isset($columns['percentual'], $columns['percentual_bps']));

echo 'Total: ' . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
