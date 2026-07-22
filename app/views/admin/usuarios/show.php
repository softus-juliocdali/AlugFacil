<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (?string $data): string => $data ? date('d/m/Y', strtotime($data)) : '-';
$formatarMoeda = static fn (float $valor): string => 'R$ ' . number_format($valor, 2, ',', '.');
$novoStatus = $usuario['status'] === 'bloqueado' ? 'ativo' : 'bloqueado';
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1><?= e($usuario['nome']) ?></h1>
        <p>Dados b&aacute;sicos e hist&oacute;rico de reservas do cliente.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/admin/usuarios') ?>">Voltar</a>
</div>

<section class="admin-detail-grid">
    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Dados b&aacute;sicos</h2>
        </div>
        <dl class="admin-detail-list">
            <div><dt>Nome</dt><dd><?= e($usuario['nome']) ?></dd></div>
            <div><dt>Telefone</dt><dd><?= e($usuario['telefone'] ?: '-') ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($usuario['email']) ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-pill"><?= e($formatarStatus($usuario['status'])) ?></span></dd></div>
            <div><dt>Cadastro</dt><dd><?= e($formatarData($usuario['data_cadastro'])) ?></dd></div>
            <div><dt>Atualizacao</dt><dd><?= e($formatarData($usuario['data_atualizacao'])) ?></dd></div>
            <div><dt>Reservas realizadas</dt><dd><?= e((string) (int) $usuario['total_reservas']) ?></dd></div>
        </dl>
        <form class="admin-status-action" method="post" action="<?= url('/admin/usuarios/' . (int) $usuario['id'] . '/status') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= e($novoStatus) ?>">
            <button class="btn <?= $novoStatus === 'bloqueado' ? 'btn-danger' : 'btn-primary' ?>" type="submit">
                <?= $novoStatus === 'bloqueado' ? 'Bloquear usuario' : 'Ativar usuario' ?>
            </button>
        </form>
    </article>

    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Resumo</h2>
        </div>
        <dl class="admin-detail-list">
            <div><dt>Total de reservas</dt><dd><?= e((string) (int) $usuario['total_reservas']) ?></dd></div>
            <div><dt>Acesso</dt><dd><?= e($usuario['status'] === 'ativo' ? 'Liberado' : 'Bloqueado') ?></dd></div>
        </dl>
    </article>
</section>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Hist&oacute;rico de reservas</h2>
    </div>

    <?php if (empty($reservas)): ?>
        <div class="empty-preview">
            <span>&#9633;</span>
            <div>
                <strong>Nenhuma reserva encontrada</strong>
                <p>Este cliente ainda n&atilde;o realizou reservas.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="responsive-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Reserva</th>
                        <th>Ch&aacute;cara</th>
                        <th>Propriet&aacute;rio</th>
                        <th>Per&iacute;odo</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Pagamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservas as $reserva): ?>
                        <tr>
                            <td>#<?= e((string) (int) $reserva['id']) ?></td>
                            <td><?= e($reserva['chacara_nome']) ?></td>
                            <td><?= e($reserva['proprietario_nome']) ?></td>
                            <td><?= e($formatarData($reserva['data_inicio'])) ?> ate <?= e($formatarData($reserva['data_fim'])) ?></td>
                            <td><?= e($formatarMoeda((float) $reserva['valor_total'])) ?></td>
                            <td><span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span></td>
                            <td><span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
