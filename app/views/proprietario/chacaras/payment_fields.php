<?php
// Shared by property creation/editing and monthly-fee preferences.
$paymentConfigured = $limites['entrada_minima_bps'] !== null;
$paymentEnabled = !empty($preferenciaPagamento['aceita_parcelamento']);
$paymentEntry = $preferenciaPagamento['entrada'] ?? ($preferenciaPagamento['entrada_bps'] === null ? '' : number_format((int) $preferenciaPagamento['entrada_bps'] / 100, 2, ',', ''));
?>
<div class="owner-payment-fields form-full">
    <p>O pagamento integral continua disponível. Esta preferência vale para novas reservas deste imóvel e também pode ser alterada em Mensalidades.</p>
    <?php if ($paymentConfigured): ?>
        <p>Entrada permitida: <?= e(number_format((int) $limites['entrada_minima_bps'] / 100, 2, ',', '')) ?>% a <?= e(number_format((int) $limites['entrada_maxima_bps'] / 100, 2, ',', '')) ?>%.</p>
    <?php else: ?>
        <p>O administrador ainda precisa definir a faixa de entrada para liberar o parcelamento.</p>
    <?php endif; ?>
    <input type="hidden" name="versao" value="<?= (int) ($preferenciaPagamento['versao'] ?? 0) ?>">
    <label class="owner-payment-toggle"><input type="checkbox" name="aceita_parcelamento" value="1" <?= $paymentEnabled ? 'checked' : '' ?> <?= $paymentConfigured ? '' : 'disabled' ?>><span>Aceita entrada + parcelamento</span></label>
    <label class="owner-payment-percent">Entrada (%)<input name="entrada" inputmode="decimal" value="<?= e($paymentEntry) ?>" <?= $paymentConfigured ? '' : 'disabled' ?>></label>
    <?php if ($paymentEnabled && $paymentConfigured && $preferenciaPagamento['entrada_bps'] !== null && ($preferenciaPagamento['entrada_bps'] < $limites['entrada_minima_bps'] || $preferenciaPagamento['entrada_bps'] > $limites['entrada_maxima_bps'])): ?>
        <p class="form-error">Sua entrada está fora da faixa atual. Ajuste o percentual para oferecer parcelamento em novos checkouts.</p>
    <?php endif; ?>
</div>
