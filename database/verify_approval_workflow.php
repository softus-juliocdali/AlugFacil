<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $parts = explode('\\', substr($class, 4));
    $parts[0] = strtolower($parts[0]);
    $file = APP_ROOT . '/app/' . implode('/', $parts) . '.php';
    if (is_file($file)) require $file;
});

$db = App\Core\Database::getConnection();
$checks = [
    'database_alugfacil_dev' => "SELECT current_database() = 'alugfacil_dev'",
    'tabelas_historico' => "SELECT COUNT(*) = 2 FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('historico_status_proprietarios','historico_status_chacaras')",
    'colunas_status_imovel' => "SELECT COUNT(*) = 2 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'chacaras' AND column_name IN ('status_aprovacao','status_operacional')",
    'constraint_proprietario_aceita_rejeitado' => "SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'proprietarios'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%rejeitado%')",
    'constraint_imovel_aceita_rejeitada' => "SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'chacaras'::regclass AND conname = 'chk_chacaras_status_aprovacao' AND pg_get_constraintdef(oid) LIKE '%rejeitada%')",
    'mapeamento_completo' => "SELECT NOT EXISTS (SELECT 1 FROM chacaras WHERE status_aprovacao IS NULL OR status_operacional IS NULL)",
    'publicacao_exige_conta_ativa' => "SELECT NOT EXISTS (SELECT 1 FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id JOIN usuarios u ON u.id=p.usuario_id WHERE c.status_aprovacao='aprovada' AND c.status_operacional='disponivel' AND u.status<>'ativo')",
];

$falhas = 0;
foreach ($checks as $nome => $sql) {
    $ok = (bool) $db->query($sql)->fetchColumn();
    echo ($ok ? '[OK] ' : '[FALHA] ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

$db->beginTransaction();
try {
    $usuario = $db->prepare("INSERT INTO usuarios (nome,email,senha_hash,tipo_usuario,status) VALUES ('Teste workflow','codex-workflow@invalid.local','x','proprietario','ativo') RETURNING id");
    $usuario->execute();
    $usuarioId = (int) $usuario->fetchColumn();
    $owner = $db->prepare("INSERT INTO proprietarios (usuario_id,nome,email,status) VALUES (:usuario_id,'Teste workflow','codex-workflow@invalid.local','ativo') RETURNING id");
    $owner->execute(['usuario_id' => $usuarioId]);
    $ownerId = (int) $owner->fetchColumn();
    $chacaraModel = new App\Models\Chacara();
    $chacaraId = $chacaraModel->criarParaProprietario($ownerId, [
        'nome' => 'Imovel de teste', 'descricao' => '', 'tipo_imovel' => 'chacara',
        'valor_diaria' => '100.00', 'cidade' => 'Teste', 'regiao' => '', 'endereco' => 'Teste',
        'latitude' => null, 'longitude' => null, 'status' => 'disponivel',
        'checkin_hora_inicial' => '14:00', 'checkin_hora_final' => '18:00',
        'checkout_hora_inicial' => '08:00', 'checkout_hora_final' => '11:00',
    ]);
    $novo = $db->query("SELECT status_aprovacao, status_operacional FROM chacaras WHERE id = {$chacaraId}")->fetch();
    $testesTransacionais = [
        'imovel_novo_pendente' => $novo['status_aprovacao'] === 'pendente' && $novo['status_operacional'] === 'indisponivel',
        'post_status_manipulado_ignorado' => $novo['status_aprovacao'] !== 'aprovada',
        'imovel_pendente_fora_publico' => $chacaraModel->buscarPerfil($chacaraId) === null,
        'imovel_pendente_sem_reserva' => (new App\Models\Reserva())->buscarChacaraParaReserva($chacaraId) === null,
    ];
    $db->prepare("UPDATE proprietarios SET status = 'rejeitado' WHERE id = :id")->execute(['id' => $ownerId]);
    $db->prepare("UPDATE chacaras SET status_aprovacao = 'rejeitada' WHERE id = :id")->execute(['id' => $chacaraId]);
    $testesTransacionais['update_proprietario_para_rejeitado'] = $db->query("SELECT status = 'rejeitado' FROM proprietarios WHERE id = {$ownerId}")->fetchColumn();
    $testesTransacionais['update_imovel_para_rejeitada'] = $db->query("SELECT status_aprovacao = 'rejeitada' FROM chacaras WHERE id = {$chacaraId}")->fetchColumn();
    foreach ($testesTransacionais as $nome => $ok) {
        echo ($ok ? '[OK] ' : '[FALHA] ') . $nome . PHP_EOL;
        if (!$ok) $falhas++;
    }
    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    echo '[FALHA] regressao_transacional: ' . $exception->getMessage() . PHP_EOL;
    $falhas++;
}

echo 'tabelas=' . $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")->fetchColumn() . PHP_EOL;
foreach (['usuarios', 'proprietarios'] as $tabela) {
    echo $tabela . '_status=' . implode(',', $db->query("SELECT DISTINCT status FROM {$tabela} ORDER BY status")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
}
echo 'chacaras_aprovacao=' . implode(',', $db->query('SELECT DISTINCT status_aprovacao FROM chacaras ORDER BY status_aprovacao')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo 'chacaras_operacional=' . implode(',', $db->query('SELECT DISTINCT status_operacional FROM chacaras ORDER BY status_operacional')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
