<?php
$statusLabel=static fn(string $s):string=>match($s){'SEM_MENSALIDADE'=>'Sem mensalidade','EM_DIA'=>'Em dia','ATRASADA'=>'Atrasada','CANCELADA'=>'Cancelada',default=>'Pendente'};
$money=static fn(?int $v):string=>'R$ '.number_format(((int)$v)/100,2,',','.');
?>
<div class="panel-page-heading"><div><span>Financeiro</span><h1>Mensalidade do an&uacute;ncio</h1><p>Acompanhe as obriga&ccedil;&otilde;es mensais de cada an&uacute;ncio.</p></div></div>
<?php foreach($notificacoes as $n): ?><section class="panel-card"><strong><?= e($n['titulo']) ?></strong><p><?= e($n['mensagem']) ?></p></section><?php endforeach; ?>
<section class="panel-card"><div class="panel-card-heading"><h2>Situa&ccedil;&atilde;o atual</h2></div>
<?php if(!$mensalidades): ?><div class="empty-preview"><div><strong>Nenhuma mensalidade configurada</strong><p>Consulte suas condições comerciais e as obrigações emitidas abaixo.</p></div></div><?php else: ?>
<div class="responsive-table"><table class="panel-table"><thead><tr><th>Ch&aacute;cara</th><th>Valor mensal</th><th>Status</th><th>Vencimento</th><th>Pr&oacute;xima cobran&ccedil;a</th><th></th></tr></thead><tbody>
<?php foreach($mensalidades as $m): ?><tr><td><?= e($m['chacara_nome']) ?></td><td><?= $m['ativa_flag']?$money((int)$m['valor_centavos']):'Sem mensalidade' ?></td><td><span class="status-pill"><?= e($statusLabel($m['status'])) ?></span></td><td><?= e($m['ultimo_vencimento']?date('d/m/Y',strtotime($m['ultimo_vencimento'])):'-') ?></td><td><?= e($m['proximo_vencimento']?date('d/m/Y',strtotime($m['proximo_vencimento'])):'-') ?></td><td><?php if(!empty($m['invoice_url'])&&in_array($m['status'],['PENDENTE','ATRASADA'],true)): ?><a class="btn btn-primary" href="<?= url('/proprietario/mensalidades/'.(int)$m['chacara_id'].'/pagar') ?>">Pagar / regularizar</a><?php elseif($m['ativa_flag']&&in_array($m['status'],['PENDENTE','ATRASADA'],true)): ?><small>A primeira cobran&ccedil;a est&aacute; sendo preparada. Tente novamente em instantes.</small><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="panel-card"><div class="panel-card-heading"><h2>Hist&oacute;rico de cobran&ccedil;as</h2></div><div class="responsive-table"><table class="panel-table"><thead><tr><th>Ch&aacute;cara</th><th>Valor</th><th>Status</th><th>Vencimento</th></tr></thead><tbody><?php foreach($historico as $h): ?><tr><td><?= e($h['chacara_nome']) ?></td><td><?= $money((int)$h['valor_centavos']) ?></td><td><?= e($statusLabel($h['status']==='PAGA'?'EM_DIA':$h['status'])) ?></td><td><?= e($h['vencimento']?date('d/m/Y',strtotime($h['vencimento'])):'-') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel-card"><h2>Condições comerciais</h2>
<?php foreach($condicoes as $condicao): ?><p><strong><?= e($condicao['nome']) ?></strong> — Comissão sobre hospedagem: <?= $condicao['comissao_bps']===null?'Aguardando configuração':e(number_format((int)$condicao['comissao_bps']/100,2,',','')).'%' ?>. Mensalidade: <?= !$condicao['mensalidade_global_ativa']||$condicao['sem_mensalidade']?'Sem cobrança mensal':e($money((int)$condicao['valor_padrao_centavos'])) ?>.</p><?php endforeach; ?></section>
<section class="panel-card"><h2>Obrigações e pagamentos</h2>
<?php if(!$obrigacoes): ?><p>Nenhuma obrigação emitida.</p><?php endif; ?>
<?php foreach($obrigacoes as $obrigacao): ?>
<article><h3><?= e($obrigacao['chacara_nome']) ?></h3><p><?= e($obrigacao['vencimento']) ?> — <?= e($money((int)$obrigacao['valor_centavos'])) ?> — <?= e($obrigacao['estado']) ?></p>
<?php if($obrigacao['estado']==='pendente'): ?><form method="post" action="<?= url('/proprietario/obrigacoes-mensais/'.(int)$obrigacao['id'].'/pagar') ?>">
<?= csrf_field() ?>
<?php if($obrigacao['forma_pagamento']): ?><input type="hidden" name="forma_pagamento" value="<?= e($obrigacao['forma_pagamento']) ?>"><p>Meio escolhido: <?= $obrigacao['forma_pagamento']==='PIX'?'PIX':'Cartão' ?></p>
<?php else: ?><label>Forma de pagamento<select name="forma_pagamento"><option value="PIX">PIX</option><option value="CREDIT_CARD">Cartão na página do Asaas</option></select></label><?php endif; ?>
<button class="btn btn-primary" type="submit">Abrir pagamento seguro</button></form><?php endif; ?></article>
<?php endforeach; ?></section>
<section class="panel-card"><h2>Pagamento das reservas</h2>
<?php if($limites['entrada_minima_bps']===null): ?><p>O administrador ainda precisa definir a faixa de entrada para liberar o parcelamento.</p>
<?php else: ?><p>Entrada permitida: <?= e(number_format((int)$limites['entrada_minima_bps']/100,2,',','')) ?>% a <?= e(number_format((int)$limites['entrada_maxima_bps']/100,2,',','')) ?>%. Pagamento integral continua disponível.</p><?php endif; ?>
<?php foreach($condicoes as $condicao): ?>
<form method="post" action="<?= url('/proprietario/chacaras/'.(int)$condicao['id'].'/pagamento') ?>" class="owner-property-form">
<h3><?= e($condicao['nome']) ?></h3><?= csrf_field() ?><input type="hidden" name="versao" value="<?= (int)($condicao['versao']??0) ?>">
<label><input type="checkbox" name="aceita_parcelamento" value="1" <?= !empty($condicao['aceita_parcelamento'])?'checked':'' ?> <?= $limites['entrada_minima_bps']===null?'disabled':'' ?>> Aceita entrada + parcelamento</label>
<label>Entrada (%)<input name="entrada" inputmode="decimal" value="<?= $condicao['entrada_bps']===null?'':e(number_format((int)$condicao['entrada_bps']/100,2,',','')) ?>" <?= $limites['entrada_minima_bps']===null?'disabled':'' ?>></label>
<?php if(!empty($condicao['aceita_parcelamento'])&&$limites['entrada_minima_bps']!==null&&($condicao['entrada_bps']<$limites['entrada_minima_bps']||$condicao['entrada_bps']>$limites['entrada_maxima_bps'])): ?><p class="form-error">Sua entrada está fora da faixa atual. Ajuste o percentual para oferecer parcelamento em novos checkouts.</p><?php endif; ?>
<button class="btn btn-primary" type="submit">Salvar preferência de pagamento</button></form>
<?php endforeach; ?></section>
