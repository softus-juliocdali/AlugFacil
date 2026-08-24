<?php
$basisPoints = (int) ($config['percentual_bps'] ?? 1000);
$formatted = intdiv($basisPoints, 100) . ',' . str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
?>
<header class="panel-page-heading"><div><span>Administração</span><h1>Comissão dos afiliados</h1><p>Percentual global aplicado a todos os afiliados.</p></div><a class="btn btn-outline" href="<?= url('/admin/afiliados') ?>">Voltar</a></header>
<section class="panel-card affiliate-commission-card">
    <div class="panel-card-heading"><div><h2>Comissão dos afiliados</h2><p>Percentual aplicado aos próximos pagamentos confirmados.</p></div></div>
    <form class="owner-property-form affiliate-commission-form" method="post" action="<?= url('/admin/afiliados/configuracao') ?>">
        <?= csrf_field() ?>
        <label>Percentual de comissão<div class="affiliate-percent-field"><input type="text" inputmode="decimal" name="percentual" value="<?= e(old('percentual', $formatted)) ?>" placeholder="10,00" aria-describedby="affiliate-percent-help" required><span aria-hidden="true">%</span></div><small id="affiliate-percent-help">Maior que 0 e no máximo 100%, com até duas casas decimais.</small></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar configuração</button></div>
    </form>
    <p class="affiliate-form-note">O percentual vigente é registrado em cada pagamento confirmado. Alterações afetam somente comissões futuras e não recalculam o histórico.</p>
</section>
