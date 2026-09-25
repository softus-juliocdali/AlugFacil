<header class="panel-page-heading"><div><span>Administra&ccedil;&atilde;o</span><h1>Configura&ccedil;&otilde;es financeiras</h1><p>Configure a taxa operacional e os limites de entrada para novas reservas.</p></div></header>
<section class="panel-card"><form class="owner-property-form" method="post" action="<?= url('/admin/configuracoes-financeiras') ?>"><?= csrf_field() ?>
<label class="form-full">Taxa operacional da reserva (centavos)<input type="number" name="taxa_operacao_pix_reserva_centavos" min="0" value="<?= e((string)($config['taxa_operacao_pix_reserva_centavos']??200)) ?>" required></label>
<label class="form-full">Motivo da altera&ccedil;&atilde;o<textarea name="motivo" maxlength="500" required></textarea></label><div class="form-actions"><button class="btn btn-primary" type="submit">Salvar nova vers&atilde;o</button></div></form></section>
<section class="panel-card"><h2>Hist&oacute;rico imut&aacute;vel</h2><div class="responsive-table"><table class="panel-table"><thead><tr><th>Vers&atilde;o</th><th>Administrador</th><th>Motivo</th><th>Data</th></tr></thead><tbody><?php foreach($historico as$h):?><tr><td><?= (int)$h['versao'] ?></td><td><?= e($h['administrador_nome']) ?></td><td><?= e($h['motivo']) ?></td><td><?= e(date('d/m/Y H:i',strtotime($h['criado_em']))) ?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="panel-card"><h2>Faixa de entrada do parcelamento</h2>
<p>O proprietário escolhe a entrada dentro desta faixa. O percentual deve reservar uma parte para a entrada e outra para o saldo.</p>
<form method="post" action="<?= url('/admin/configuracoes-financeiras/parcelamento') ?>" class="owner-property-form"><?= csrf_field() ?>
<label>Entrada mínima (%)<input name="entrada_minima" inputmode="decimal" required value="<?= $limites['entrada_minima_bps']===null?'':e(number_format((int)$limites['entrada_minima_bps']/100,2,',','')) ?>"></label>
<label>Entrada máxima (%)<input name="entrada_maxima" inputmode="decimal" required value="<?= $limites['entrada_maxima_bps']===null?'':e(number_format((int)$limites['entrada_maxima_bps']/100,2,',','')) ?>"></label>
<button class="btn btn-primary" type="submit">Salvar faixa de entrada</button></form>
<p>Checkouts já iniciados e reservas existentes preservam suas condições.</p></section>
