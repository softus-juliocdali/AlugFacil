<header class="panel-page-header"><div><span class="eyebrow">Área financeira</span><h1>Recebimentos</h1><p>Acompanhe sua conta vinculada e as pendências para receber reservas.</p></div></header>
<section class="panel-card"><h2>Situação financeira</h2>
<dl class="client-summary-list">
<div><dt>Cadastro</dt><dd><?= !empty($dados['dados_completos'])?'Completo':(($dados['situacao']??'')==='conflito'?'Divergência cadastral pendente':'Incompleto') ?></dd></div>
<div><dt>Autorização</dt><dd><?= $aceite?'Registrada':'Pendente' ?></dd></div>
<div><dt>Subconta</dt><dd><?= e(str_replace('_',' ',$subconta['status_local']??'não iniciada')) ?></dd></div>
<div><dt>Provisionamento</dt><dd><?= e(str_replace('_',' ',$provisionamento['estado']??'Aguardando cadastro completo')) ?></dd></div>
<?php if(!empty($subconta['asaas_account_id'])): ?><div><dt>Identificador da conta Asaas</dt><dd><?= e($subconta['asaas_account_id']) ?></dd></div><?php endif; ?>
<div><dt>Análise cadastral Asaas</dt><dd><?= e($subconta['status_cadastral_asaas']??'Pendente') ?></dd></div>
<div><dt>Situação operacional Asaas</dt><dd><?= e($subconta['status_operacional_asaas']??'Não informada') ?></dd></div>
<div><dt>Última sincronização</dt><dd><?= e($subconta['ultima_sincronizacao_em']??'Ainda não realizada') ?></dd></div>
</dl>
<?php if(($provisionamento['estado']??'')==='conciliacao_manual'): ?><p class="form-error">A criação da conta está em conciliação. O suporte precisa confirmar o resultado no Asaas antes de uma nova tentativa. Você pode continuar cadastrando seus imóveis.</p><?php endif; ?>
<?php if(!empty($subconta['ultimo_erro_sanitizado'])): ?><p class="form-error">Existe uma pendência de integração. Consulte o suporte para acompanhar a regularização.</p><?php endif; ?>
<p>Identificação, contato e endereço são mantidos em Dados Cadastrais. A aprovação cadastral do Asaas e a disponibilidade financeira são etapas distintas.</p>
<a class="btn btn-outline" href="<?= url('/proprietario/dados-cadastrais') ?>">Atualizar dados cadastrais</a></section>
<section class="panel-card"><h2>Histórico financeiro da conta</h2>
<?php if(empty($historico)): ?><p>Nenhuma atualização de conta registrada.</p><?php else: ?><ul><?php foreach($historico as $evento): ?><li><?= e($evento['criado_em']) ?> — <?= e(str_replace('_',' ',$evento['status_novo'])) ?> (<?= e($evento['origem']) ?>)</li><?php endforeach; ?></ul><?php endif; ?></section>
<section class="panel-card"><h2>Autorização</h2><p><?= e($termo) ?></p>
<?php if(!$aceite): ?><form method="post" action="<?= url('/proprietario/recebimentos/aceite') ?>"><?= csrf_field() ?><label><input type="checkbox" name="aceite_explicito" value="1" required> Li e autorizo explicitamente.</label><div class="form-actions"><button class="btn btn-primary" type="submit">Registrar autorização</button></div></form><?php else: ?><p>Autorização financeira registrada.</p><?php endif; ?></section>
