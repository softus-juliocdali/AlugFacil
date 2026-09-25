<?php
$statusLabel=static fn(string $s):string=>match($s){'SEM_MENSALIDADE'=>'Sem mensalidade','EM_DIA'=>'Em dia','ATRASADA'=>'Atrasada','CANCELADA'=>'Cancelada',default=>'Pendente'};
$money=static fn(?int $v):string=>'R$ '.number_format(((int)$v)/100,2,',','.');
?>
<div class="owner-financial-page">
<div class="panel-page-heading"><div><span>Financeiro</span><h1>Mensalidade do an&uacute;ncio</h1><p>Acompanhe as obriga&ccedil;&otilde;es mensais de cada an&uacute;ncio.</p></div></div>
<?php foreach($notificacoes as $n): ?><section class="panel-card"><strong><?= e($n['titulo']) ?></strong><p><?= e($n['mensagem']) ?></p></section><?php endforeach; ?>
<section class="panel-card"><div class="panel-card-heading"><h2>Situa&ccedil;&atilde;o atual</h2></div>
<?php if(!$mensalidades): ?><div class="empty-preview"><div><strong>Nenhuma mensalidade configurada</strong><p>Consulte suas condições comerciais e as obrigações emitidas abaixo.</p></div></div><?php else: ?>
<div class="responsive-table"><table class="panel-table"><thead><tr><th>Ch&aacute;cara</th><th>Valor mensal</th><th>Status</th><th>Vencimento</th><th>Pr&oacute;xima cobran&ccedil;a</th><th></th></tr></thead><tbody>
<?php foreach($mensalidades as $m): ?><tr><td><?= e($m['chacara_nome']) ?></td><td><?= $m['ativa_flag']?$money((int)$m['valor_centavos']):'Sem mensalidade' ?></td><td><span class="status-pill"><?= e($statusLabel($m['status'])) ?></span></td><td><?= e($m['ultimo_vencimento']?date('d/m/Y',strtotime($m['ultimo_vencimento'])):'-') ?></td><td><?= e($m['proximo_vencimento']?date('d/m/Y',strtotime($m['proximo_vencimento'])):'-') ?></td><td><?php if(!empty($m['invoice_url'])&&in_array($m['status'],['PENDENTE','ATRASADA'],true)): ?><a class="btn btn-primary" href="<?= url('/proprietario/mensalidades/'.(int)$m['chacara_id'].'/pagar') ?>">Pagar / regularizar</a><?php elseif($m['ativa_flag']&&in_array($m['status'],['PENDENTE','ATRASADA'],true)): ?><small>A primeira cobran&ccedil;a est&aacute; sendo preparada. Tente novamente em instantes.</small><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="panel-card"><div class="panel-card-heading"><h2>Hist&oacute;rico de cobran&ccedil;as</h2></div>
<?php if (!$historico): ?><div class="empty-preview"><div><strong>Nenhuma cobrança no histórico</strong><p>As cobranças emitidas aparecerão aqui.</p></div></div><?php else: ?>
<div class="responsive-table"><table class="panel-table"><thead><tr><th>Ch&aacute;cara</th><th>Valor</th><th>Status</th><th>Vencimento</th></tr></thead><tbody><?php foreach($historico as $h): ?><tr><td><?= e($h['chacara_nome']) ?></td><td><?= $money((int)$h['valor_centavos']) ?></td><td><?= e($statusLabel($h['status']==='PAGA'?'EM_DIA':$h['status'])) ?></td><td><?= e($h['vencimento']?date('d/m/Y',strtotime($h['vencimento'])):'-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="panel-card"><div class="panel-card-heading"><h2>Condições comerciais</h2></div>
<?php if (!$condicoes): ?><div class="empty-preview"><div><strong>Nenhuma condição comercial configurada</strong><p>As condições serão exibidas por imóvel.</p></div></div><?php endif; ?>
<?php foreach($condicoes as $condicao): ?><article class="owner-commercial-condition"><h3><?= e($condicao['nome']) ?></h3><dl class="client-summary-list"><div><dt>Comissão sobre hospedagem</dt><dd><?= $condicao['comissao_bps']===null?'Aguardando configuração':e(number_format((int)$condicao['comissao_bps']/100,2,',','')).'%' ?></dd></div><div><dt>Mensalidade</dt><dd><?= !$condicao['mensalidade_global_ativa']||$condicao['sem_mensalidade']?'Sem cobrança mensal':e($money((int)$condicao['valor_padrao_centavos'])) ?></dd></div></dl></article><?php endforeach; ?></section>
<section class="panel-card"><div class="panel-card-heading"><h2>Obrigações e pagamentos</h2></div>
<?php if(!$obrigacoes): ?><div class="empty-preview"><div><strong>Nenhuma obrigação emitida</strong><p>As obrigações mensais aparecerão aqui quando forem emitidas.</p></div></div><?php endif; ?>
<?php foreach($obrigacoes as $obrigacao): ?>
<article class="owner-obligation"><h3><?= e($obrigacao['chacara_nome']) ?></h3><p><?= e($obrigacao['vencimento']) ?> — <?= e($money((int)$obrigacao['valor_centavos'])) ?> — <?= e($obrigacao['estado']) ?></p>
<?php if($obrigacao['estado']==='pendente'): ?><form class="owner-obligation-form" method="post" action="<?= url('/proprietario/obrigacoes-mensais/'.(int)$obrigacao['id'].'/pagar') ?>">
<?= csrf_field() ?>
<?php if($obrigacao['forma_pagamento']): ?><input type="hidden" name="forma_pagamento" value="<?= e($obrigacao['forma_pagamento']) ?>"><p>Meio escolhido: <?= $obrigacao['forma_pagamento']==='PIX'?'PIX':'Cartão' ?></p>
<?php else: ?><label>Forma de pagamento<select name="forma_pagamento"><option value="PIX">PIX</option><option value="CREDIT_CARD">Cartão na página do Asaas</option></select></label><?php endif; ?>
<button class="btn btn-primary" type="submit">Abrir pagamento seguro</button></form><?php endif; ?></article>
<?php endforeach; ?></section>
<section class="panel-card"><div class="panel-card-heading"><h2>Pagamento das reservas</h2></div>
<?php if (!$condicoes): ?><div class="empty-preview"><div><strong>Nenhum imóvel cadastrado</strong><p>Cadastre um imóvel para definir suas preferências de pagamento.</p></div></div><?php endif; ?>
<?php foreach($condicoes as $condicao): $preferenciaPagamento = $condicao; ?>
<form method="post" action="<?= url('/proprietario/chacaras/'.(int)$condicao['id'].'/pagamento') ?>" class="owner-property-form owner-payment-card">
<div class="form-full"><h3><?= e($condicao['nome']) ?></h3></div><?= csrf_field() ?>
<?php require APP_ROOT . '/app/views/proprietario/chacaras/payment_fields.php'; ?>
<div class="form-actions"><button class="btn btn-primary" type="submit">Salvar preferência de pagamento</button></div></form>
<?php endforeach; ?></section>
</div>
