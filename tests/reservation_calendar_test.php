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
use App\Services\ReservaStatusService;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste do calendario permitido somente em alugfacil_dev.');
}

$checks = [];
$ownerUserId = 0;
$ownerId = 0;
$clientUserId = 0;
$chacaraId = 0;
$date = static fn (int $offset): string => (new DateTimeImmutable('today'))
    ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
    ->format('Y-m-d');

try {
    $tag = bin2hex(random_bytes(5));
    $users = new User();
    $ownerUserId = $users->createOwner([
        'nome' => 'Proprietario Calendario',
        'telefone' => '11999999999',
        'email' => "owner-calendar-{$tag}@localhost.test",
        'senha' => 'TesteLocal123!',
    ]);
    $ownerId = (int) ($users->findOwnerByUserId($ownerUserId)['id'] ?? 0);

    $client = $db->prepare(
        "INSERT INTO usuarios (nome,email,senha_hash,tipo_usuario,status)
         VALUES ('Cliente Calendario',:email,'teste','cliente','ativo') RETURNING id"
    );
    $client->execute(['email' => "client-calendar-{$tag}@localhost.test"]);
    $clientUserId = (int) $client->fetchColumn();

    $chacaras = new Chacara();
    $chacaraId = $chacaras->criarParaProprietario($ownerId, [
        'nome' => 'Chacara Calendario',
        'descricao' => 'Fixture isolada para testar datas de reserva.',
        'tipo_imovel' => 'chacara',
        'valor_diaria' => '300.00',
        'cidade' => 'Sorocaba',
        'estado' => 'SP',
        'regiao' => 'Centro',
        'endereco' => 'Rua do Calendario, 10',
        'latitude' => '-23.5015000',
        'longitude' => '-47.4526000',
        'checkin_hora_inicial' => '14:00',
        'checkin_hora_final' => '18:00',
        'checkout_hora_inicial' => '08:00',
        'checkout_hora_final' => '11:00',
    ]);

    $db->prepare(
        "UPDATE chacaras SET status_aprovacao = 'aprovada', status_operacional = 'disponivel' WHERE id = :id"
    )->execute(['id' => $chacaraId]);
    $db->prepare(
        "INSERT INTO mensalidades_anuncios
            (chacara_id,proprietario_id,ativa,valor_centavos,status)
         VALUES (:chacara_id,:proprietario_id,TRUE,4990,'ATRASADA')"
    )->execute(['chacara_id' => $chacaraId, 'proprietario_id' => $ownerId]);
    $reservas = new Reserva();
    $checks['chacara_inadimplente_nao_pode_ser_reservada_com_datas_livres'] = $reservas->buscarChacaraParaReserva(
        $chacaraId
    ) === null;

    $insertReservation = $db->prepare(
        'INSERT INTO reservas
            (usuario_id,proprietario_id,chacara_id,data_inicio,data_fim,quantidade_diarias,
             valor_diaria,valor_total,status_reserva,status_pagamento,expira_em)
         VALUES
            (:usuario_id,:proprietario_id,:chacara_id,:inicio,:fim,:diarias,
             300.00,:total,:status,\'pendente\',CAST(:expira_em AS timestamp))'
    );
    $addReservation = static function (
        string $status,
        int $startOffset,
        int $endOffset,
        ?string $expiration
    ) use ($insertReservation, $clientUserId, $ownerId, $chacaraId, $date): void {
        $nights = $endOffset - $startOffset;
        $insertReservation->execute([
            'usuario_id' => $clientUserId,
            'proprietario_id' => $ownerId,
            'chacara_id' => $chacaraId,
            'inicio' => $date($startOffset),
            'fim' => $date($endOffset),
            'diarias' => $nights,
            'total' => number_format($nights * 300, 2, '.', ''),
            'status' => $status,
            'expira_em' => $expiration,
        ]);
    };

    $addReservation('confirmada', 10, 12, null);
    $addReservation('cancelada', 14, 16, null);
    $addReservation('expirada', 18, 20, null);
    $addReservation('aguardando_pagamento', 22, 24, date('Y-m-d H:i:s', time() + 3600));
    $addReservation('aguardando_pagamento', 26, 28, date('Y-m-d H:i:s', time() - 3600));

    $db->prepare(
        "INSERT INTO disponibilidades (chacara_id,data,status,observacao)
         VALUES (:chacara_id,:data,'bloqueado','Teste de bloqueio do proprietario')"
    )->execute(['chacara_id' => $chacaraId, 'data' => $date(30)]);

    $calendarRows = $chacaras->buscarDatasIndisponiveis($chacaraId, $date(1), $date(35));
    $blockedDates = array_values(array_unique(array_column($calendarRows, 'data')));
    sort($blockedDates);
    $expectedDates = [$date(10), $date(11), $date(22), $date(23), $date(30)];
    sort($expectedDates);

    $checks['calendario_consolida_bloqueio_reserva_e_pendente_valida'] = $blockedDates === $expectedDates;
    $checks['checkout_exclusivo_nao_bloqueia_dia_final'] = !in_array($date(12), $blockedDates, true)
        && !in_array($date(24), $blockedDates, true);
    $checks['cancelada_expirada_e_pendente_vencida_nao_bloqueiam'] = !array_intersect(
        [$date(14), $date(15), $date(18), $date(19), $date(26), $date(27)],
        $blockedDates
    );

    $checks['backend_aceita_reservas_consecutivas'] = !$reservas->existeConflitoReserva(
        $chacaraId,
        $date(8),
        $date(10)
    ) && !$reservas->existeConflitoReserva($chacaraId, $date(12), $date(14));
    $checks['backend_rejeita_sobreposicao_e_intervalo_que_atravessa_reserva'] = $reservas->existeConflitoReserva(
        $chacaraId,
        $date(9),
        $date(13)
    ) && $reservas->existeConflitoReserva($chacaraId, $date(11), $date(13));
    $checks['backend_ignora_status_finais_e_pendente_vencida'] = !$reservas->existeConflitoReserva(
        $chacaraId,
        $date(14),
        $date(16)
    ) && !$reservas->existeConflitoReserva($chacaraId, $date(18), $date(20))
        && !$reservas->existeConflitoReserva($chacaraId, $date(26), $date(28));
    $checks['backend_bloqueio_proprietario_respeita_checkout_exclusivo'] = !$reservas->existeIndisponibilidade(
        $chacaraId,
        $date(28),
        $date(30)
    ) && $reservas->existeIndisponibilidade($chacaraId, $date(29), $date(32));
    $checks['periodo_valido_exige_futuro_e_fim_posterior'] = Reserva::periodoValido($date(1), $date(2))
        && !Reserva::periodoValido($date(2), $date(2))
        && !Reserva::periodoValido($date(-2), $date(-1));

    $view = (string) file_get_contents(dirname(__DIR__) . '/app/views/public/reserva_criar.php');
    $javascript = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/main.js');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/ReservaController.php');
    $reservaSource = (string) file_get_contents(dirname(__DIR__) . '/app/models/Reserva.php');
    $chacaraSource = (string) file_get_contents(dirname(__DIR__) . '/app/models/Chacara.php');

    $checks['interface_usa_flatpickr_range_em_portugues_e_fallback_nativo'] = str_contains($view, 'flatpickr@4.6.13')
        && str_contains($view, 'dist/l10n/pt.js')
        && str_contains($view, 'data-reservation-native')
        && str_contains($javascript, "mode: 'range'")
        && str_contains($javascript, 'disableMobile: true');
    $checks['frontend_bloqueia_datas_passadas'] = str_contains($javascript, 'minDate,')
        && str_contains($controller, "new DateTimeImmutable('today'")
        && str_contains($view, 'data-min-date');
    $checks['frontend_impede_cruzar_indisponibilidade_sem_bloquear_checkout'] = str_contains(
        $javascript,
        'key >= startKey && key < endKey'
    ) && str_contains($javascript, 'firstUnavailableAfter')
        && str_contains($javascript, 'is-checkout-boundary');
    $checks['janela_de_doze_meses_e_datas_minimas_vem_do_backend'] = str_contains(
        $controller,
        "modify('+12 months')"
    ) && str_contains($view, 'data-min-date') && str_contains($view, 'data-max-date');
    $checks['cotacao_preserva_id_e_protege_datas'] = str_contains($view, 'name="cotacao_id"')
        && str_contains($view, 'data-locked')
        && str_contains($javascript, 'clickOpens: !locked');
    $checks['status_que_bloqueia_tem_fonte_unica'] = count(ReservaStatusService::BLOQUEIAM_DATAS) === 6
        && str_contains($reservaSource, 'ReservaStatusService::listaSqlBloqueiamDatas()')
        && substr_count($chacaraSource, 'ReservaStatusService::listaSqlBloqueiamDatas()') >= 4;
} finally {
    if ($chacaraId > 0) {
        $db->prepare('DELETE FROM reservas WHERE chacara_id = :id')->execute(['id' => $chacaraId]);
        $db->prepare('DELETE FROM disponibilidades WHERE chacara_id = :id')->execute(['id' => $chacaraId]);
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
