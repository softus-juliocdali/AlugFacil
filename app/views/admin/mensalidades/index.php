<?php
$money=static fn(?int $centavos):string=>$centavos===null?'-':'R$ '.number_format($centavos/100,2,',','.');
$date=static fn(?string $valor,bool $hora=false):string=>$valor?date($hora?'d/m/Y H:i':'d/m/Y',strtotime($valor)):'-';
$labels=['EM_DIA'=>'Em dia','PENDENTE'=>'Pendente','ATRASADA'=>'Atrasada','CANCELADA'=>'Cancelada','SEM_MENSALIDADE'=>'Sem mensalidade','AGUARDANDO_CONFIGURACAO'=>'Aguardando configuração obrigatória'];
$label=static fn(?string $valor):string=>$labels[$valor??'SEM_MENSALIDADE']??ucfirst(strtolower(str_replace('_',' ',(string)$valor)));
$subscription=static function(?string $id):string{$id=trim((string)$id);return $id===''?'-':(strlen($id)>20?substr($id,0,10).'...'.substr($id,-6):$id);};
?>
<div class="panel-page-heading"><div><span>Administracao</span><h1>Mensalidades</h1><p>Cobrancas de anuncio consolidadas por chacara.</p></div></div>

<section class="panel-card">
    <div class="panel-card-heading"><h2>Mensalidade global de anúncios</h2></div>
    <p>O valor global vale para novas obrigações de todos os imóveis não isentos. Obrigações já emitidas preservam seu valor.</p>
    <form class="owner-property-form" method="post" action="<?= url('/admin/mensalidades/configuracao') ?>">
        <?= csrf_field() ?>
        <label><input type="checkbox" name="ativa" value="1" <?= !empty($configuracaoMensalidade['ativa'])?'checked':'' ?>> Mensalidade de anúncios ativa</label>
        <label>Valor global da mensalidade (R$)
            <input type="text" inputmode="decimal" name="valor_padrao_mensal" required value="<?= e(number_format(((int)$configuracaoMensalidade['valor_padrao_centavos'])/100,2,',','')) ?>">
        </label>
        <label class="form-full">Motivo da alteracao
            <input type="text" name="motivo" maxlength="500" required>
        </label>
        <button class="btn btn-primary" type="submit">Salvar configuração global</button>
    </form>
</section>

<section class="panel-card">
    <form class="billing-filter-form" method="get" action="<?= url('/admin/mensalidades') ?>">
        <label>Status <select name="status">
            <?php foreach([''=>'Todas','AGUARDANDO_CONFIGURACAO'=>'Aguardando configuração obrigatória','EM_DIA'=>'Em dia','PENDENTE'=>'Pendentes','ATRASADA'=>'Atrasadas','CANCELADA'=>'Canceladas','SEM_MENSALIDADE'=>'Sem mensalidade'] as $valor=>$rotulo): ?>
                <option value="<?= e($valor) ?>" <?= strtoupper($status)===strtoupper($valor)?'selected':'' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Buscar chacara ou proprietario <input type="search" name="busca" value="<?= e($busca) ?>"></label>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
    <div class="responsive-table"><table class="panel-table"><thead><tr><th>Chacara</th><th>Proprietario</th><th>Valor</th><th>Status</th><th>Vencimento atual</th><th>Ultimo pagamento</th><th>Proxima cobranca</th><th>Assinatura Asaas</th><th></th></tr></thead><tbody>
    <?php if(!$mensalidades): ?><tr><td colspan="9">Nenhum registro encontrado.</td></tr><?php endif; ?>
    <?php foreach($mensalidades as $m): ?>
        <tr>
            <td><?= e($m['chacara_nome']) ?></td><td><?= e($m['proprietario_nome']) ?></td>
            <td><?= e($money($m['valor_centavos']!==null?(int)$m['valor_centavos']:null)) ?></td>
            <td><span class="status-pill"><?= e($label($m['status'])) ?></span><?php if(!empty($m['ultima_falha_sincronizacao'])): ?><small><?= e($m['ultima_falha_sincronizacao']) ?></small><?php endif; ?></td>
            <td><?= e($date($m['vencimento_atual'])) ?></td><td><?= e($date($m['ultimo_pagamento'],true)) ?></td><td><?= e($date($m['proximo_vencimento'])) ?></td>
            <td title="<?= e($m['asaas_subscription_id']??'') ?>"><?= e($subscription($m['asaas_subscription_id'])) ?></td>
            <td><a class="btn btn-outline" href="<?= url('/admin/chacaras/'.(int)$m['chacara_id']) ?>">Detalhes</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel-card"><div class="panel-card-heading"><h2>Historico do valor padrao</h2></div>
<?php if(!$historicoConfiguracao): ?><p>Nenhuma alteracao registrada.</p><?php else: ?><div class="responsive-table"><table class="panel-table"><thead><tr><th>Data</th><th>Administrador</th><th>Anterior</th><th>Novo</th><th>Motivo</th></tr></thead><tbody>
<?php foreach($historicoConfiguracao as $h): ?><tr><td><?= e($date($h['criado_em'],true)) ?></td><td><?= e($h['administrador_nome']??'Administrador removido') ?></td><td><?= e($money($h['valor_anterior_centavos']!==null?(int)$h['valor_anterior_centavos']:null)) ?></td><td><?= e($money((int)$h['valor_novo_centavos'])) ?></td><td><?= e($h['motivo']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
