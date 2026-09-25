<?php
$label=static fn(string $valor):string=>ucfirst(str_replace('_',' ',strtolower($valor)));
$money=static fn(?int $centavos):string=>$centavos===null?'-':'R$ '.number_format($centavos/100,2,',','.');
$date=static fn(?string $valor,bool $hora=false):string=>$valor?date($hora?'d/m/Y H:i':'d/m/Y',strtotime($valor)):'-';
$transicoes=['pendente'=>['aprovada'=>'Aprovar','rejeitada'=>'Rejeitar'],'aprovada'=>['bloqueada'=>'Bloquear'],'bloqueada'=>['aprovada'=>'Desbloquear','pendente'=>'Devolver para analise'],'rejeitada'=>['pendente'=>'Reabrir analise']];
$valorSugerido=(int)($mensalidade['valor_centavos']??$valorMensalPadraoCentavos);
$mensalidadeConfigurada=$mensalidade!==null;
$mensalidadeAtiva=$mensalidadeConfigurada&&!empty($mensalidade['ativa_flag']);
$mensalidadeObrigatoriaAfiliado=!empty($mensalidadeObrigatoriaAfiliado);
$mensalidadeObrigatoriaSemConfiguracao=$mensalidadeObrigatoriaAfiliado&&(!$mensalidadeConfigurada||!$mensalidadeAtiva||($mensalidade['status']??null)==='SEM_MENSALIDADE');
$mensalidadeStatusExibicao=$mensalidadeObrigatoriaSemConfiguracao?'AGUARDANDO_CONFIGURACAO':($mensalidade['status']??'SEM_MENSALIDADE');
?>
<div class="panel-page-heading"><div><span>Administracao</span><h1><?= e($chacara['nome']) ?></h1><p>Dados, fotos, decisao administrativa e mensalidade do imovel.</p></div><a class="btn btn-outline" href="<?= url('/admin/chacaras') ?>">Voltar</a></div>

<section class="panel-card"><h2>Condição comercial do imóvel</h2>
<p>Mensalidade global: <?= e($money($valorMensalPadraoCentavos)) ?>. A comissão incide somente sobre hospedagem.</p>
<form method="post" action="<?= url('/admin/chacaras/'.(int)$chacara['id'].'/comercial') ?>" class="owner-property-form">
<?= csrf_field() ?><label><input type="checkbox" name="sem_mensalidade" value="1" <?= !empty($comercial['sem_mensalidade'])?'checked':'' ?>> Sem mensalidade (somente comissão)</label>
<label>Comissão da hospedagem (%)<input name="comissao" inputmode="decimal" required value="<?= isset($comercial['comissao_bps'])?e(number_format((int)$comercial['comissao_bps']/100,2,',','')):'' ?>"></label>
<label>Motivo<input name="motivo" required maxlength="1000"></label><button class="btn btn-primary" type="submit">Salvar condição comercial</button></form></section>
<section class="admin-detail-grid">
<article class="panel-card"><div class="panel-card-heading"><h2>Dados do imovel</h2></div><dl class="admin-detail-list">
<div><dt>Proprietario</dt><dd><?= e($chacara['proprietario_nome']) ?></dd></div><div><dt>Status da conta</dt><dd><?= e($label($chacara['usuario_status'])) ?></dd></div><div><dt>Cidade</dt><dd><?= e($chacara['cidade']) ?></dd></div><div><dt>Endereco</dt><dd><?= e($chacara['endereco']) ?></dd></div><div><dt>Aprovacao</dt><dd><span class="status-pill"><?= e($label($chacara['status_aprovacao'])) ?></span></dd></div><div><dt>Disponibilidade</dt><dd><?= e($label($chacara['status_operacional'])) ?></dd></div></dl>
<?php if($mensalidadeObrigatoriaAfiliado): ?><div class="alert affiliate-monthly-notice"><strong>Proprietário indicado por afiliado</strong><span>Mensalidade obrigatória<?= !empty($chacara['afiliado_codigo'])?' · Código: '.e($chacara['afiliado_codigo']):'' ?></span></div><?php endif; ?>
<form id="admin-status-form" class="admin-status-action owner-property-form" method="post" action="<?= url('/admin/chacaras/'.(int)$chacara['id'].'/status') ?>">
<?= csrf_field() ?><label class="form-full">Motivo <input type="text" name="motivo" value="<?= e($chacara['motivo_status']??'') ?>"></label>
<div class="form-actions"><?php foreach($transicoes[$chacara['status_aprovacao']]??[] as $novo=>$rotulo): ?><button class="btn <?= in_array($novo,['bloqueada','rejeitada'],true)?'btn-danger':'btn-primary' ?>" name="status" value="<?= e($novo) ?>" type="submit"<?= $novo==='aprovada'?'':' formnovalidate' ?>><?= e($rotulo) ?></button><?php endforeach; ?></div></form></article>

<article class="panel-card"><div class="panel-card-heading"><h2>Mensalidade do anuncio</h2></div><dl class="admin-detail-list">
<div><dt>Status</dt><dd><?= e($label($mensalidadeStatusExibicao)) ?></dd></div><div><dt>Valor</dt><dd><?= e($money(isset($mensalidade['valor_centavos'])?(int)$mensalidade['valor_centavos']:null)) ?></dd></div><div><dt>Proximo vencimento</dt><dd><?= e($date($mensalidade['proximo_vencimento']??null)) ?></dd></div><div><dt>Ultimo pagamento</dt><dd><?= e($date($mensalidade['ultimo_pagamento_em']??null,true)) ?></dd></div><div><dt>Assinatura Asaas</dt><dd><?= e($mensalidade['asaas_subscription_id']??'-') ?></dd></div></dl>
<?php if(!empty($mensalidade['ultima_falha_sincronizacao'])): ?><div class="alert alert-error"><?= e($mensalidade['ultima_falha_sincronizacao']) ?> Tente aprovar novamente para conciliar sem criar outra assinatura.</div><?php endif; ?></article>

<article class="panel-card"><div class="panel-card-heading"><h2>Fotos</h2></div><?php if(!$fotos): ?><p>Nenhuma foto cadastrada.</p><?php else: ?><div class="owner-photo-grid"><?php foreach($fotos as $foto): ?><img src="<?= asset($foto['caminho_foto']) ?>" alt="Foto de <?= e($chacara['nome']) ?>"><?php endforeach; ?></div><?php endif; ?></article>
</section>

<section class="panel-card"><div class="panel-card-heading"><h2>Cobrancas da mensalidade</h2></div><?php if(!$cobrancasMensalidade): ?><p>Nenhuma cobranca sincronizada.</p><?php else: ?><div class="responsive-table"><table class="panel-table"><thead><tr><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pagamento</th><th>ID Asaas</th><th>Fatura</th></tr></thead><tbody><?php foreach($cobrancasMensalidade as $c): ?><tr><td><?= e($date($c['vencimento'])) ?></td><td><?= e($money((int)$c['valor_centavos'])) ?></td><td><?= e($label($c['status'])) ?></td><td><?= e($date($c['pago_em'],true)) ?></td><td><?= e($c['asaas_payment_id']) ?></td><td><?php if(!empty($c['invoice_url'])): ?><a class="btn btn-outline" href="<?= e($c['invoice_url']) ?>" target="_blank" rel="noopener noreferrer">Abrir</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel-card"><div class="panel-card-heading"><h2>Historico da mensalidade</h2></div><?php if(!$historicoMensalidade): ?><p>Nenhuma alteracao registrada.</p><?php else: ?><div class="responsive-table"><table class="panel-table"><thead><tr><th>Data</th><th>Origem</th><th>Anterior</th><th>Novo status</th><th>Valor</th></tr></thead><tbody><?php foreach($historicoMensalidade as $h): ?><tr><td><?= e($date($h['criado_em'],true)) ?></td><td><?= e($label($h['origem'])) ?></td><td><?= e($label($h['status_anterior']??'-')) ?></td><td><?= e($label($h['status_novo'])) ?></td><td><?= e($h['valor_centavos']!==null?$money((int)$h['valor_centavos']):'-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
