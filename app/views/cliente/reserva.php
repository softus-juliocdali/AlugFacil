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
$podeAvaliar = ($reserva['status_reserva'] ?? '') === 'finalizada' && empty($reserva['avaliacao_id']);
?>

<header class="panel-page-heading">
    <div>
        <span>Reserva #<?= e((string) $reserva['id']) ?></span>
        <h1><?= e($reserva['chacara_nome']) ?></h1>
        <p><?= e($reserva['cidade']) ?><?= !empty($reserva['regiao']) ? ' · ' . e($reserva['regiao']) : '' ?></p>
    </div>
    <a class="btn btn-outline" href="<?= url('/cliente/historico') ?>">Voltar</a>
</header>

<section class="client-detail-layout">
    <article class="client-detail-hero">
        <img src="<?= e($foto) ?>" alt="Foto de <?= e($reserva['chacara_nome']) ?>">
        <div>
            <span class="client-card-kicker">Dados da chacara</span>
            <h2><?= e($reserva['chacara_nome']) ?></h2>
            <p><?= e($reserva['chacara_descricao'] ?: 'Chacara cadastrada no Alug Facil.') ?></p>
            <address><?= e($reserva['endereco']) ?></address>
        </div>
    </article>

    <aside class="panel-card client-detail-summary">
        <h2>Resumo da reserva</h2>
        <dl class="client-summary-list">
            <div><dt>Proprietario</dt><dd><?= e($reserva['proprietario_nome']) ?></dd></div>
            <div><dt>Datas</dt><dd><?= e(date('d/m/Y', strtotime($reserva['data_inicio']))) ?> ate <?= e(date('d/m/Y', strtotime($reserva['data_fim']))) ?></dd></div>
            <div><dt>Diarias</dt><dd><?= e((string) $reserva['quantidade_diarias']) ?></dd></div>
            <div><dt>Valor</dt><dd>R$ <?= e(number_format((float) $reserva['valor_total'], 2, ',', '.')) ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span></dd></div>
            <div><dt>Pagamento</dt><dd><span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span></dd></div>
            <?php if (!empty($reserva['expira_em'])): ?><div><dt>Expira em</dt><dd><?= e(date('d/m/Y H:i', strtotime($reserva['expira_em']))) ?></dd></div><?php endif; ?>
        </dl>

        <div class="client-detail-actions">
            <a class="btn btn-primary" href="<?= url('/reserva/confirmacao/' . (int)$reserva['id']) ?>"><?= in_array($reserva['status_reserva'], ['aguardando_pagamento','confirmada','em_andamento'], true) ? 'Pagar Reserva' : 'Ver pagamentos da reserva' ?></a>

            <?php if ($podeAvaliar): ?>
                <a class="btn btn-outline" href="<?= url('/chacara/' . (int) $reserva['chacara_id']) ?>">Avaliar</a>
            <?php endif; ?>
            <?php if (($reserva['modalidade'] ?? 'integral') === 'integral' && in_array($reserva['status_reserva'], ['aguardando_pagamento','confirmada','em_andamento'], true)): ?>
                <form method="post" action="<?= url('/cliente/reserva/'.(int)$reserva['id'].'/cancelar') ?>"><?= csrf_field() ?><label>Motivo (opcional)<textarea name="motivo" maxlength="500"></textarea></label><button class="btn btn-outline" type="submit"><?= $reserva['status_reserva']==='aguardando_pagamento'?'Cancelar reserva':'Solicitar cancelamento' ?></button></form>
            <?php endif; ?>
        </div>
    </aside>
</section>

<?php if (!empty($historico)): ?>
<section class="panel-card"><div class="panel-card-heading"><h2>Historico da reserva</h2></div>
<?php foreach ($historico as $evento): ?><p><strong><?= e($formatarStatus($evento['status_novo'])) ?></strong> · <?= e(date('d/m/Y H:i', strtotime($evento['criado_em']))) ?><?= !empty($evento['motivo']) ? ' · '.e($evento['motivo']) : '' ?></p><?php endforeach; ?>
</section>
<?php endif; ?>
