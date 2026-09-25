<header class="panel-page-heading"><div><span>Administração</span><h1>Comissão individual dos afiliados</h1><p>A comissão incide somente sobre mensalidades, nunca sobre reservas.</p></div></header>
<?php foreach($affiliates as $affiliate): ?>
<section class="panel-card"><h2><?= e($affiliate['nome']) ?> — <?= e($affiliate['codigo']) ?></h2>
<form method="post" action="<?= url('/admin/afiliados/configuracao') ?>" class="owner-property-form">
<?= csrf_field() ?><input type="hidden" name="afiliado_id" value="<?= (int)$affiliate['id'] ?>">
<label>Percentual (0 a 100%)<input name="percentual" inputmode="decimal" required value="<?= e(number_format((int)$affiliate['percentual_comissao_bps']/100,2,',','')) ?>"></label>
<label>Motivo<input name="motivo" maxlength="1000" required></label>
<button class="btn btn-primary" type="submit">Salvar percentual individual</button></form>
<p>Novas mensalidades usam este percentual. Obrigações e comissões anteriores preservam seu histórico.</p></section>
<?php endforeach; ?>
