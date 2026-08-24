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

<section class="admin-detail-grid">
<article class="panel-card"><div class="panel-card-heading"><h2>Dados do imovel</h2></div><dl class="admin-detail-list">
<div><dt>Proprietario</dt><dd><?= e($chacara['proprietario_nome']) ?></dd></div><div><dt>Status da conta</dt><dd><?= e($label($chacara['usuario_status'])) ?></dd></div><div><dt>Cidade</dt><dd><?= e($chacara['cidade']) ?></dd></div><div><dt>Endereco</dt><dd><?= e($chacara['endereco']) ?></dd></div><div><dt>Aprovacao</dt><dd><span class="status-pill"><?= e($label($chacara['status_aprovacao'])) ?></span></dd></div><div><dt>Disponibilidade</dt><dd><?= e($label($chacara['status_operacional'])) ?></dd></div></dl>
<?php if($mensalidadeObrigatoriaAfiliado): ?><div class="alert affiliate-monthly-notice"><strong>Proprietário indicado por afiliado</strong><span>Mensalidade obrigatória<?= !empty($chacara['afiliado_codigo'])?' · Código: '.e($chacara['afiliado_codigo']):'' ?></span></div><?php endif; ?>
<form id="admin-status-form" class="admin-status-action owner-property-form" method="post" action="<?= url('/admin/chacaras/'.(int)$chacara['id'].'/status') ?>">
<?= csrf_field() ?><label class="form-full">Motivo <input type="text" name="motivo" value="<?= e($chacara['motivo_status']??'') ?>"></label>
<?php if(isset(($transicoes[$chacara['status_aprovacao']]??[])['aprovada'])): ?>
<fieldset class="form-full approval-monthly-choice">
    <legend>Mensalidade na aprovacao</legend>
    <div class="approval-monthly-options">
        <?php if(!$mensalidadeObrigatoriaAfiliado): ?>
        <label class="approval-monthly-option">
            <input class="approval-monthly-radio" type="radio" name="mensalidade" value="sem" required<?= $mensalidadeConfigurada&&!$mensalidadeAtiva?' checked':'' ?>>
            <span class="approval-monthly-card">
                <span class="approval-monthly-check" aria-hidden="true">&#10003;</span>
                <span><strong>Sem mensalidade</strong><small>Publicacao sem cobranca</small></span>
            </span>
        </label>
        <?php endif; ?>
        <label class="approval-monthly-option">
            <input class="approval-monthly-radio" type="radio" name="mensalidade" value="com" required<?= $mensalidadeObrigatoriaAfiliado||$mensalidadeAtiva?' checked':'' ?>>
            <span class="approval-monthly-card">
                <span class="approval-monthly-check" aria-hidden="true">&#10003;</span>
                <span><strong>Com mensalidade</strong><small>Publicacao apos pagamento</small></span>
            </span>
        </label>
    </div>
    <small class="approval-monthly-help"><?= $mensalidadeObrigatoriaAfiliado?'Este proprietário possui vínculo com afiliado; a mensalidade é obrigatória.':'Escolha uma opcao para concluir a aprovacao.' ?></small>
</fieldset>
<label class="form-full approval-monthly-value" data-approval-monthly-value>Valor mensal (R$)
    <input type="text" inputmode="decimal" name="valor_mensal" value="<?= e(number_format($valorSugerido/100,2,',','')) ?>">
    <small><?= $mensalidadeObrigatoriaAfiliado?'Obrigatório para esta chácara.':'Obrigatorio somente ao aprovar com mensalidade.' ?></small>
</label>
<?php endif; ?>
<div class="form-actions"><?php foreach($transicoes[$chacara['status_aprovacao']]??[] as $novo=>$rotulo): ?><button class="btn <?= in_array($novo,['bloqueada','rejeitada'],true)?'btn-danger':'btn-primary' ?>" name="status" value="<?= e($novo) ?>" type="submit"<?= $novo==='aprovada'?'':' formnovalidate' ?>><?= e($rotulo) ?></button><?php endforeach; ?></div></form></article>

<article class="panel-card"><div class="panel-card-heading"><h2>Mensalidade do anuncio</h2></div><dl class="admin-detail-list">
<div><dt>Status</dt><dd><?= e($label($mensalidadeStatusExibicao)) ?></dd></div><div><dt>Valor</dt><dd><?= e($money(isset($mensalidade['valor_centavos'])?(int)$mensalidade['valor_centavos']:null)) ?></dd></div><div><dt>Proximo vencimento</dt><dd><?= e($date($mensalidade['proximo_vencimento']??null)) ?></dd></div><div><dt>Ultimo pagamento</dt><dd><?= e($date($mensalidade['ultimo_pagamento_em']??null,true)) ?></dd></div><div><dt>Assinatura Asaas</dt><dd><?= e($mensalidade['asaas_subscription_id']??'-') ?></dd></div></dl>
<?php if(!empty($mensalidade['ultima_falha_sincronizacao'])): ?><div class="alert alert-error"><?= e($mensalidade['ultima_falha_sincronizacao']) ?> Tente aprovar novamente para conciliar sem criar outra assinatura.</div><?php endif; ?></article>

<article class="panel-card"><div class="panel-card-heading"><h2>Fotos</h2></div><?php if(!$fotos): ?><p>Nenhuma foto cadastrada.</p><?php else: ?><div class="owner-photo-grid"><?php foreach($fotos as $foto): ?><img src="<?= asset($foto['caminho_foto']) ?>" alt="Foto de <?= e($chacara['nome']) ?>"><?php endforeach; ?></div><?php endif; ?></article>
</section>

<section class="panel-card"><div class="panel-card-heading"><h2>Cobrancas da mensalidade</h2></div><?php if(!$cobrancasMensalidade): ?><p>Nenhuma cobranca sincronizada.</p><?php else: ?><div class="responsive-table"><table class="panel-table"><thead><tr><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pagamento</th><th>ID Asaas</th><th>Fatura</th></tr></thead><tbody><?php foreach($cobrancasMensalidade as $c): ?><tr><td><?= e($date($c['vencimento'])) ?></td><td><?= e($money((int)$c['valor_centavos'])) ?></td><td><?= e($label($c['status'])) ?></td><td><?= e($date($c['pago_em'],true)) ?></td><td><?= e($c['asaas_payment_id']) ?></td><td><?php if(!empty($c['invoice_url'])): ?><a class="btn btn-outline" href="<?= e($c['invoice_url']) ?>" target="_blank" rel="noopener noreferrer">Abrir</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel-card"><div class="panel-card-heading"><h2>Historico da mensalidade</h2></div><?php if(!$historicoMensalidade): ?><p>Nenhuma alteracao registrada.</p><?php else: ?><div class="responsive-table"><table class="panel-table"><thead><tr><th>Data</th><th>Origem</th><th>Anterior</th><th>Novo status</th><th>Valor</th></tr></thead><tbody><?php foreach($historicoMensalidade as $h): ?><tr><td><?= e($date($h['criado_em'],true)) ?></td><td><?= e($label($h['origem'])) ?></td><td><?= e($label($h['status_anterior']??'-')) ?></td><td><?= e($label($h['status_novo'])) ?></td><td><?= e($h['valor_centavos']!==null?$money((int)$h['valor_centavos']):'-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<script>
(() => {
    const form = document.getElementById('admin-status-form');
    const valueInput = form?.querySelector('[name="valor_mensal"]');
    const monthlyOptions = form?.querySelectorAll('[name="mensalidade"]') ?? [];
    if (!valueInput || monthlyOptions.length === 0) return;
    const syncRequired = () => {
        const selected = form.querySelector('[name="mensalidade"]:checked')?.value;
        const withMonthlyFee = selected === 'com';
        valueInput.required = withMonthlyFee;
        valueInput.disabled = !withMonthlyFee;
        valueInput.closest('[data-approval-monthly-value]').hidden = !withMonthlyFee;
    };
    monthlyOptions.forEach(option => option.addEventListener('change', syncRequired));
    syncRequired();
})();
</script>
