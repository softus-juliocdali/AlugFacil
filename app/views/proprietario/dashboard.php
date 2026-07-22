<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (string $data): string => date('d/m/Y', strtotime($data));
?>
<div class="panel-page-heading">
    <div>
        <span>&Aacute;rea do propriet&aacute;rio</span>
        <h1>Dashboard</h1>
        <p>Acompanhe reservas, faturamento e disponibilidade das suas ch&aacute;caras.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/proprietario/chacaras/criar') ?>">Cadastrar ch&aacute;cara</a>
</div>

<div class="metric-grid owner-metric-grid">
    <article>
        <span>Total de reservas</span>
        <strong><?= e((string) (int) ($resumo['total_reservas'] ?? 0)) ?></strong>
        <small>Apenas reservas vinculadas ao seu cadastro.</small>
    </article>
    <article>
        <span>Total de faturamento</span>
        <strong>R$ <?= e(number_format((float) ($resumo['total_faturamento'] ?? 0), 2, ',', '.')) ?></strong>
        <small>Soma das reservas com pagamento pago.</small>
    </article>
    <article>
        <span>Total de ch&aacute;caras cadastradas</span>
        <strong><?= e((string) (int) ($resumo['total_chacaras'] ?? 0)) ?></strong>
        <small>Im&oacute;veis cadastrados para este propriet&aacute;rio.</small>
    </article>
    <article>
        <span>Total de reservas confirmadas</span>
        <strong><?= e((string) (int) ($resumo['total_confirmadas'] ?? 0)) ?></strong>
        <small>Reservas com status confirmado.</small>
    </article>
    <article>
        <span>Total de reservas pendentes</span>
        <strong><?= e((string) (int) ($resumo['total_pendentes'] ?? 0)) ?></strong>
        <small>Solicitadas ou aguardando pagamento.</small>
    </article>
</div>

<div class="owner-dashboard-grid">
    <section class="panel-card">
        <div class="panel-card-heading">
            <h2>5 &uacute;ltimas reservas</h2>
        </div>

        <?php if (empty($ultimasReservas)): ?>
            <div class="empty-preview">
                <span>▣</span>
                <div>
                    <strong>Nenhuma reserva encontrada</strong>
                    <p>Quando houver reservas nas suas ch&aacute;caras, elas aparecer&atilde;o aqui.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="owner-reservation-list">
                <?php foreach ($ultimasReservas as $reserva): ?>
                    <article class="owner-reservation-item">
                        <div>
                            <span class="client-card-kicker">Reserva #<?= e((string) $reserva['id']) ?></span>
                            <strong><?= e($reserva['cliente_nome']) ?></strong>
                            <p><?= e($reserva['chacara_nome']) ?> · <?= e($formatarData($reserva['data_inicio'])) ?> ate <?= e($formatarData($reserva['data_fim'])) ?></p>
                        </div>
                        <div class="owner-reservation-side">
                            <strong>R$ <?= e(number_format((float) $reserva['valor_total'], 2, ',', '.')) ?></strong>
                            <span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span>
                            <span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel-card">
        <div class="panel-card-heading">
            <h2>Disponibilidade resumida</h2>
        </div>

        <?php if (empty($disponibilidades)): ?>
            <div class="empty-preview">
                <span>□</span>
                <div>
                    <strong>Nenhuma indisponibilidade futura</strong>
                    <p>Sem datas reservadas ou bloqueadas cadastradas para suas ch&aacute;caras.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="owner-availability-list">
                <?php foreach ($disponibilidades as $disponibilidade): ?>
                    <article>
                        <time datetime="<?= e($disponibilidade['data']) ?>"><?= e($formatarData($disponibilidade['data'])) ?></time>
                        <div>
                            <strong><?= e($disponibilidade['chacara_nome']) ?></strong>
                            <p><?= e($disponibilidade['cidade']) ?><?= !empty($disponibilidade['observacao']) ? ' · ' . e($disponibilidade['observacao']) : '' ?></p>
                        </div>
                        <span class="status-pill"><?= e($formatarStatus($disponibilidade['status'])) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
