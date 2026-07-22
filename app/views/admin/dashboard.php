<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (string $data): string => date('d/m/Y', strtotime($data));
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1>Dashboard administrativo</h1>
        <p>Visao geral de chacaras, usuarios, proprietarios, reservas e faturamento.</p>
    </div>
</div>

<div class="metric-grid admin-metric-grid">
    <article>
        <span>Total de ch&aacute;caras cadastradas</span>
        <strong><?= e((string) (int) ($resumo['total_chacaras'] ?? 0)) ?></strong>
        <small>Todos os im&oacute;veis da plataforma.</small>
    </article>
    <article>
        <span>Total de ch&aacute;caras dispon&iacute;veis</span>
        <strong><?= e((string) (int) ($resumo['total_disponiveis'] ?? 0)) ?></strong>
        <small>Com status dispon&iacute;vel para listagem.</small>
    </article>
    <article>
        <span>Total de ch&aacute;caras locadas</span>
        <strong><?= e((string) (int) ($resumo['total_locadas'] ?? 0)) ?></strong>
        <small>Com reserva confirmada no per&iacute;odo atual.</small>
    </article>
    <article>
        <span>Total de faturamento</span>
        <strong>R$ <?= e(number_format((float) ($resumo['total_faturamento'] ?? 0), 2, ',', '.')) ?></strong>
        <small>Reservas pagas ou confirmadas.</small>
    </article>
    <article>
        <span>Total de usu&aacute;rios clientes</span>
        <strong><?= e((string) (int) ($resumo['total_clientes'] ?? 0)) ?></strong>
        <small>Contas cadastradas como cliente.</small>
    </article>
    <article>
        <span>Total de propriet&aacute;rios</span>
        <strong><?= e((string) (int) ($resumo['total_proprietarios'] ?? 0)) ?></strong>
        <small>Cadastros na tabela de propriet&aacute;rios.</small>
    </article>
    <article>
        <span>Total de reservas</span>
        <strong><?= e((string) (int) ($resumo['total_reservas'] ?? 0)) ?></strong>
        <small>Hist&oacute;rico geral da plataforma.</small>
    </article>
    <article>
        <span>Total de reservas pendentes</span>
        <strong><?= e((string) (int) ($resumo['total_pendentes'] ?? 0)) ?></strong>
        <small>Solicitadas ou aguardando confirma&ccedil;&atilde;o.</small>
    </article>
    <article>
        <span>Total de reservas confirmadas</span>
        <strong><?= e((string) (int) ($resumo['total_confirmadas'] ?? 0)) ?></strong>
        <small>Reservas com status confirmado.</small>
    </article>
</div>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Reservas recentes</h2>
    </div>

    <?php if (empty($ultimasReservas)): ?>
        <div class="empty-preview">
            <span>&#9633;</span>
            <div>
                <strong>Nenhuma reserva encontrada</strong>
                <p>As novas reservas da plataforma aparecer&atilde;o aqui.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="responsive-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Reserva</th>
                        <th>Ch&aacute;cara</th>
                        <th>Cliente</th>
                        <th>Propriet&aacute;rio</th>
                        <th>Per&iacute;odo</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Pagamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimasReservas as $reserva): ?>
                        <tr>
                            <td>#<?= e((string) $reserva['id']) ?></td>
                            <td><?= e($reserva['chacara_nome']) ?></td>
                            <td><?= e($reserva['cliente_nome']) ?></td>
                            <td><?= e($reserva['proprietario_nome']) ?></td>
                            <td><?= e($formatarData($reserva['data_inicio'])) ?> ate <?= e($formatarData($reserva['data_fim'])) ?></td>
                            <td>R$ <?= e(number_format((float) $reserva['valor_total'], 2, ',', '.')) ?></td>
                            <td><span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span></td>
                            <td><span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
