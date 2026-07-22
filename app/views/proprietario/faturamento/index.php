<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (string $data): string => date('d/m/Y', strtotime($data));
$formatarMoeda = static fn (float $valor): string => 'R$ ' . number_format($valor, 2, ',', '.');
?>
<div class="panel-page-heading">
    <div>
        <span>Financeiro</span>
        <h1>Faturamento</h1>
        <p>Acompanhe reservas pagas, confirmadas e pendentes das suas ch&aacute;caras.</p>
    </div>
</div>

<section class="panel-card billing-filter-card">
    <form class="billing-filter-form" method="get" action="<?= url('/proprietario/faturamento') ?>">
        <label>
            Per&iacute;odo
            <select name="periodo" data-billing-period>
                <option value="semana" <?= $periodo === 'semana' ? 'selected' : '' ?>>Semana</option>
                <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>M&ecirc;s</option>
                <option value="semestre" <?= $periodo === 'semestre' ? 'selected' : '' ?>>Semestre</option>
                <option value="ano" <?= $periodo === 'ano' ? 'selected' : '' ?>>Ano</option>
                <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
            </select>
        </label>
        <label>
            In&iacute;cio
            <input type="date" name="inicio" value="<?= e($inicio) ?>">
        </label>
        <label>
            Fim
            <input type="date" name="fim" value="<?= e($fim) ?>">
        </label>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
</section>

<div class="metric-grid billing-metric-grid">
    <article>
        <span>Total faturado</span>
        <strong><?= e($formatarMoeda((float) $resumo['total_faturado'])) ?></strong>
        <small>Reservas pagas ou confirmadas no per&iacute;odo.</small>
    </article>
    <article>
        <span>Estimativa</span>
        <strong><?= e($formatarMoeda((float) $resumo['estimativa_faturamento'])) ?></strong>
        <small>Reservas n&atilde;o canceladas no per&iacute;odo.</small>
    </article>
    <article>
        <span>Pendentes</span>
        <strong><?= e($formatarMoeda((float) $resumo['total_pendente'])) ?></strong>
        <small>Solicitadas ou aguardando pagamento.</small>
    </article>
    <article>
        <span>Reservas</span>
        <strong><?= e((string) (int) $resumo['total_reservas']) ?></strong>
        <small>Total encontrado no filtro atual.</small>
    </article>
</div>

<section class="panel-card billing-section">
    <div class="panel-card-heading">
        <h2>Reservas faturadas ou confirmadas</h2>
    </div>
    <?php if (empty($reservasPrincipais)): ?>
        <div class="empty-preview">
            <span>&#8599;</span>
            <div>
                <strong>Nenhuma reserva faturada</strong>
                <p>Reservas pagas ou confirmadas aparecer&atilde;o aqui.</p>
            </div>
        </div>
    <?php else: ?>
        <?php require APP_ROOT . '/app/views/proprietario/faturamento/tabela_reservas.php'; ?>
    <?php endif; ?>
</section>

<section class="panel-card billing-section">
    <div class="panel-card-heading">
        <h2>Reservas pendentes</h2>
    </div>
    <?php $reservasTabela = $reservasPendentes; ?>
    <?php if (empty($reservasTabela)): ?>
        <div class="empty-preview">
            <span>&#9636;</span>
            <div>
                <strong>Nenhuma pend&ecirc;ncia</strong>
                <p>Reservas solicitadas ou aguardando pagamento aparecer&atilde;o aqui.</p>
            </div>
        </div>
    <?php else: ?>
        <?php require APP_ROOT . '/app/views/proprietario/faturamento/tabela_reservas.php'; ?>
    <?php endif; ?>
</section>
