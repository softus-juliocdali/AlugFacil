<?php $reservasTabela ??= $reservasPrincipais; ?>
<div class="responsive-table">
    <table class="panel-table">
        <thead>
            <tr>
                <th>Reserva</th>
                <th>Ch&aacute;cara</th>
                <th>Cliente</th>
                <th>Per&iacute;odo</th>
                <th>Valor</th>
                <th>Status</th>
                <th>Pagamento</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reservasTabela as $reserva): ?>
                <tr>
                    <td>#<?= e((string) $reserva['id']) ?></td>
                    <td>
                        <strong><?= e($reserva['chacara_nome']) ?></strong><br>
                        <small><?= e($reserva['cidade']) ?></small>
                    </td>
                    <td><?= e($reserva['cliente_nome']) ?></td>
                    <td><?= e($formatarData($reserva['data_inicio'])) ?> ate <?= e($formatarData($reserva['data_fim'])) ?></td>
                    <td><?= e($formatarMoeda((float) $reserva['valor_total'])) ?></td>
                    <td><span class="status-pill"><?= e($formatarStatus($reserva['status_reserva'])) ?></span></td>
                    <td><span class="status-pill status-payment"><?= e($formatarStatus($reserva['status_pagamento'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php unset($reservasTabela); ?>
