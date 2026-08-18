<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\Chacara;
use App\Models\Reserva;
use App\Models\User;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste de elegibilidade permitido somente em alugfacil_dev.');
}

$checks = [];
$ownerUserId = 0;
$ownerId = 0;
$clientUserId = 0;
$chacaraIds = [];

try {
    $tag = bin2hex(random_bytes(5));
    $userModel = new User();
    $ownerUserId = $userModel->createOwner([
        'nome' => 'Proprietario Elegibilidade',
        'telefone' => '11999999999',
        'email' => "owner-eligibility-{$tag}@localhost.test",
        'senha' => 'TesteLocal123!',
    ]);
    $ownerId = (int) ($userModel->findOwnerByUserId($ownerUserId)['id'] ?? 0);

    $clientStatement = $db->prepare(
        "INSERT INTO usuarios (nome,email,senha_hash,tipo_usuario,status)
         VALUES ('Cliente Elegibilidade',:email,'teste','cliente','ativo') RETURNING id"
    );
    $clientStatement->execute(['email' => "client-eligibility-{$tag}@localhost.test"]);
    $clientUserId = (int) $clientStatement->fetchColumn();

    // Simula apenas o status legado de aprovacao do proprietario. A conta real segue ativa.
    $db->prepare("UPDATE proprietarios SET status = 'pendente' WHERE id = :id")
        ->execute(['id' => $ownerId]);

    $model = new Chacara();
    $createChacara = static function (Chacara $model, int $ownerId, string $nome): int {
        return $model->criarParaProprietario($ownerId, [
            'nome' => $nome,
            'descricao' => 'Teste automatizado da regra publica.',
            'tipo_imovel' => 'chacara',
            'valor_diaria' => '300.00',
            'cidade' => 'Sorocaba',
            'estado' => 'SP',
            'regiao' => 'Centro',
            'endereco' => 'Rua do Teste, 10',
            'latitude' => '-23.5015000',
            'longitude' => '-47.4526000',
            'checkin_hora_inicial' => '14:00',
            'checkin_hora_final' => '18:00',
            'checkout_hora_inicial' => '08:00',
            'checkout_hora_final' => '11:00',
        ]);
    };

    $chacaraIds = [
        'em_dia' => $createChacara($model, $ownerId, 'Chacara Em Dia'),
        'atrasada' => $createChacara($model, $ownerId, 'Chacara Atrasada'),
        'pendente' => $createChacara($model, $ownerId, 'Chacara Pendente'),
        'bloqueada' => $createChacara($model, $ownerId, 'Chacara Bloqueada'),
        'rejeitada' => $createChacara($model, $ownerId, 'Chacara Rejeitada'),
    ];

    $statusStatement = $db->prepare(
        'UPDATE chacaras SET status_aprovacao = :aprovacao, status_operacional = :operacional WHERE id = :id'
    );
    foreach ([
        'em_dia' => 'aprovada',
        'atrasada' => 'aprovada',
        'pendente' => 'pendente',
        'bloqueada' => 'bloqueada',
        'rejeitada' => 'rejeitada',
    ] as $cenario => $aprovacao) {
        $statusStatement->execute([
            'id' => $chacaraIds[$cenario],
            'aprovacao' => $aprovacao,
            'operacional' => 'disponivel',
        ]);
    }

    $monthlyStatement = $db->prepare(
        'INSERT INTO mensalidades_anuncios
            (chacara_id, proprietario_id, ativa, valor_centavos, status)
         VALUES (:chacara_id, :proprietario_id, TRUE, 4990, :status)'
    );
    foreach ($chacaraIds as $cenario => $chacaraId) {
        $monthlyStatement->execute([
            'chacara_id' => $chacaraId,
            'proprietario_id' => $ownerId,
            'status' => $cenario === 'atrasada' ? 'ATRASADA' : 'EM_DIA',
        ]);
    }

    $base = [
        'status_aprovacao' => 'aprovada',
        'status_operacional' => 'disponivel',
        'proprietario_status' => 'pendente',
        'usuario_status' => 'ativo',
    ];
    $checks['regra_php_proprietario_pendente_com_em_dia_elegivel'] = Chacara::elegivelPublicamente(
        $base,
        ['ativa' => true, 'status' => 'EM_DIA']
    );
    $checks['regra_php_atrasada_inelegivel'] = !Chacara::elegivelPublicamente(
        $base,
        ['ativa' => true, 'status' => 'ATRASADA']
    );
    $checks['regra_php_chacara_pendente_inelegivel'] = !Chacara::elegivelPublicamente(
        array_replace($base, ['status_aprovacao' => 'pendente']),
        ['ativa' => true, 'status' => 'EM_DIA']
    );
    $checks['regra_php_chacara_bloqueada_inelegivel'] = !Chacara::elegivelPublicamente(
        array_replace($base, ['status_aprovacao' => 'bloqueada']),
        ['ativa' => true, 'status' => 'EM_DIA']
    );
    $checks['regra_php_conta_bloqueada_inelegivel'] = !Chacara::elegivelPublicamente(
        array_replace($base, ['usuario_status' => 'bloqueado']),
        ['ativa' => true, 'status' => 'EM_DIA']
    );

    $checks['perfil_publico_exibe_somente_em_dia'] = $model->buscarPerfil($chacaraIds['em_dia']) !== null
        && $model->buscarPerfil($chacaraIds['atrasada']) === null
        && $model->buscarPerfil($chacaraIds['pendente']) === null
        && $model->buscarPerfil($chacaraIds['bloqueada']) === null
        && $model->buscarPerfil($chacaraIds['rejeitada']) === null;

    $reservaModel = new Reserva();
    $checks['reserva_usa_a_mesma_regra'] = $reservaModel->buscarChacaraParaReserva($chacaraIds['em_dia']) !== null
        && $reservaModel->buscarChacaraParaReserva($chacaraIds['atrasada']) === null
        && $reservaModel->buscarChacaraParaReserva($chacaraIds['pendente']) === null
        && $reservaModel->buscarChacaraParaReserva($chacaraIds['bloqueada']) === null
        && $reservaModel->buscarChacaraParaReserva($chacaraIds['rejeitada']) === null;

    $publicIds = array_map(
        'intval',
        array_column($model->buscarDisponiveis(), 'id')
    );
    $checks['home_busca_e_filtros_isolam_mensalidade_por_chacara'] = in_array(
        $chacaraIds['em_dia'],
        $publicIds,
        true
    ) && !in_array($chacaraIds['atrasada'], $publicIds, true)
        && !in_array($chacaraIds['pendente'], $publicIds, true)
        && !in_array($chacaraIds['bloqueada'], $publicIds, true)
        && !in_array($chacaraIds['rejeitada'], $publicIds, true);

    $favoriteStatement = $db->prepare(
        'INSERT INTO favoritos_chacaras (usuario_id, chacara_id) VALUES (:usuario_id, :chacara_id)'
    );
    foreach ($chacaraIds as $chacaraId) {
        $favoriteStatement->execute(['usuario_id' => $clientUserId, 'chacara_id' => $chacaraId]);
    }
    $visibleFavoriteIds = array_map(
        'intval',
        array_column($model->listarFavoritosDoUsuario($clientUserId), 'id')
    );
    $checks['favoritos_preservados_mas_apenas_elegivel_visivel'] = $model->favoritosDoUsuario($clientUserId) !== []
        && count($model->favoritosDoUsuario($clientUserId)) === count($chacaraIds)
        && $visibleFavoriteIds === [$chacaraIds['em_dia']];

    $db->prepare("UPDATE usuarios SET status = 'bloqueado' WHERE id = :id")
        ->execute(['id' => $ownerUserId]);
    $checks['bloqueio_real_da_conta_remove_publicacao_e_reserva'] = $model->buscarPerfil($chacaraIds['em_dia']) === null
        && $reservaModel->buscarChacaraParaReserva($chacaraIds['em_dia']) === null
        && !in_array($chacaraIds['em_dia'], array_map('intval', array_column($model->buscarDisponiveis(), 'id')), true)
        && $model->listarFavoritosDoUsuario($clientUserId) === [];
    $db->prepare("UPDATE usuarios SET status = 'ativo' WHERE id = :id")
        ->execute(['id' => $ownerUserId]);
} finally {
    if ($clientUserId > 0) {
        $db->prepare('DELETE FROM favoritos_chacaras WHERE usuario_id = :id')->execute(['id' => $clientUserId]);
    }
    foreach ($chacaraIds as $chacaraId) {
        $db->prepare('DELETE FROM mensalidades_anuncios WHERE chacara_id = :id')->execute(['id' => $chacaraId]);
        $db->prepare('DELETE FROM historico_status_chacaras WHERE chacara_id = :id')->execute(['id' => $chacaraId]);
        $db->prepare('DELETE FROM chacaras WHERE id = :id')->execute(['id' => $chacaraId]);
    }
    if ($ownerId > 0) {
        $db->prepare('DELETE FROM proprietarios WHERE id = :id')->execute(['id' => $ownerId]);
    }
    foreach ([$ownerUserId, $clientUserId] as $userId) {
        if ($userId > 0) {
            $db->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $userId]);
        }
    }
}

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
