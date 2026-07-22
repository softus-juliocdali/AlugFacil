<?php
$resolverFoto = static function (?string $foto, int $indice = 0): string {
    $foto = trim(str_replace('\\', '/', (string) $foto));
    $arquivo = $foto !== '' ? APP_ROOT . '/public/' . ltrim($foto, '/') : '';

    return $arquivo !== '' && is_file($arquivo)
        ? url('/' . ltrim($foto, '/'))
        : asset('img/chacara-' . (($indice % 6) + 1) . '.jpg');
};

$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
?>

<header class="panel-page-heading">
    <div>
        <span>Area do cliente</span>
        <h1>Historico de reservas</h1>
        <p>Acompanhe suas reservas, pagamentos e detalhes de cada estadia.</p>
    </div>
</header>

<section class="client-history-list">
    <?php if (empty($reservas)): ?>
        <article class="panel-card client-empty-state">
            <strong>Nenhuma reserva encontrada</strong>
            <p>Quando voce reservar uma chacara, ela aparecera aqui com status e pagamento.</p>
            <a class="btn btn-primary" href="<?= url('/') ?>">Encontrar chacaras</a>
        </article>
    <?php endif; ?>

    <?php foreach ($reservas as $indice => $reserva): ?>
        <?php $foto = $resolverFoto($reserva['foto'] ?? '', $indice); ?>
        <article class="client-reservation-card">
            <img src="<?= e($foto) ?>" alt="Foto de <?= e($reserva['chacara_nome']) ?>">
            <div class="client-reservation-content">
                <div>
                    <span class="client-card-kicker"><?= e($reserva['cidade']) ?><?= !empty($reserva['regiao']) ? ' · ' . e($reserva['regiao']) : '' ?></span>
                    <h2><?= e($reserva['chacara_nome']) ?></h2>
                </div>

                <dl class="client-reservation-meta">
                    <div><dt>Data inicial</dt><dd><?= e(date('d/m/Y', strtotime($reserva['data_inicio']))) ?></dd></div>
                    <div><dt>Data final</dt><dd><?= e(date('d/m/Y', strtotime($reserva['data_fim']))) ?></dd></div>
                    <div><dt>Valor total</dt><dd>R$ <?= e(number_format((float) $reserva['valor_total'], 2, ',', '.')) ?></dd></div>
                </dl>

                <div class="client-card-footer">
                    <div class="client-status-group">
                        <span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span>
                        <span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span>
                    </div>
                    <a class="btn btn-primary" href="<?= url('/cliente/reserva/' . (int) $reserva['id']) ?>">Ver Detalhes</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
