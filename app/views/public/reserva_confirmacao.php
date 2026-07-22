<?php
$resolverFoto = static function (?string $foto, int $indice = 0): string {
    $foto = trim(str_replace('\\', '/', (string) $foto));
    $arquivo = $foto !== '' ? APP_ROOT . '/public/' . ltrim($foto, '/') : '';
    return $arquivo !== '' && is_file($arquivo)
        ? url('/' . ltrim($foto, '/'))
        : asset('img/chacara-' . (($indice % 6) + 1) . '.jpg');
};

$foto = $resolverFoto($reserva['foto'] ?? '', ((int) $reserva['chacara_id'] - 1));
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
?>

<section class="profile-page reservation-page">
    <div class="container">
        <nav class="profile-breadcrumb" aria-label="Navegação estrutural">
            <a href="<?= url('/') ?>">Início</a><span>›</span>
            <a href="<?= url('/chacara/' . (int) $reserva['chacara_id']) ?>"><?= e($reserva['chacara_nome']) ?></a><span>›</span>
            <strong>Confirmação</strong>
        </nav>

        <div class="confirmation-card">
            <div class="confirmation-visual">
                <img src="<?= e($foto) ?>" alt="Foto de <?= e($reserva['chacara_nome']) ?>">
            </div>

            <div class="confirmation-content">
                <span class="profile-kicker">Reserva #<?= e((string) $reserva['id']) ?></span>
                <h1>Reserva criada com sucesso</h1>
                <p class="confirmation-message">Sua reserva será confirmada após o pagamento. Enquanto isso, ela permanece como aguardando pagamento.</p>

                <dl class="confirmation-details">
                    <div><dt>Chácara</dt><dd><?= e($reserva['chacara_nome']) ?></dd></div>
                    <div><dt>Cliente</dt><dd><?= e($reserva['cliente_nome']) ?></dd></div>
                    <div><dt>Proprietário</dt><dd><?= e($reserva['proprietario_nome']) ?></dd></div>
                    <div><dt>Período</dt><dd><?= e(date('d/m/Y', strtotime($reserva['data_inicio']))) ?> até <?= e(date('d/m/Y', strtotime($reserva['data_fim']))) ?></dd></div>
                    <div><dt>Quantidade de diárias</dt><dd><?= e((string) $reserva['quantidade_diarias']) ?></dd></div>
                    <div><dt>Valor da diária</dt><dd>R$ <?= e(number_format((float) $reserva['valor_diaria'], 2, ',', '.')) ?></dd></div>
                    <div class="confirmation-total"><dt>Valor total</dt><dd>R$ <?= e(number_format((float) $reserva['valor_total'], 2, ',', '.')) ?></dd></div>
                    <div><dt>Status da reserva</dt><dd><span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span></dd></div>
                    <div><dt>Status do pagamento</dt><dd><span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span></dd></div>
                </dl>

                <?php if (!empty($reserva['link_pagamento_asaas'])): ?>
                    <a class="btn btn-book" href="<?= e($reserva['link_pagamento_asaas']) ?>" target="_blank" rel="noopener">Pagar Reserva</a>
                <?php else: ?>
                    <div class="payment-empty">O link de pagamento ainda não foi gerado para esta reserva.</div>
                <?php endif; ?>

                <a class="confirmation-back" href="<?= url('/chacara/' . (int) $reserva['chacara_id']) ?>">Voltar ao perfil da chácara</a>
            </div>
        </div>
    </div>
</section>
