<?php
$statusFinanceiro = static fn (?string $status): string => match (strtoupper((string) $status)) {
    'APPROVED', 'APROVADA' => 'Aprovada', 'ENABLED' => 'Habilitada',
    'DISABLED' => 'Desabilitada', 'PENDING', 'PENDENTE' => 'Pendente',
    'AWAITING_APPROVAL', 'EM_ANALISE', 'UNDER_REVIEW' => 'Em análise',
    'REJECTED', 'REJEITADA', 'REPROVADA' => 'Reprovada',
    'BLOCKED', 'BLOQUEADA' => 'Bloqueada', 'ACTIVE', 'ATIVA' => 'Ativa',
    'INACTIVE', 'INATIVA' => 'Inativa', 'CRIADA' => 'Criada',
    'NAO_INICIADA', 'NAO_INICIADO' => 'Não iniciada', 'DADOS_INCOMPLETOS' => 'Dados incompletos',
    'CRIANDO' => 'Criando conta', 'AGUARDANDO_ATIVACAO' => 'Aguardando ativação',
    'AGUARDANDO_ASAAS' => 'Aguardando análise do Asaas',
    'AGUARDANDO_CADASTRO' => 'Aguardando cadastro completo',
    'AGUARDANDO_ACEITE' => 'Aguardando autorização',
    'PRONTO_PARA_CRIACAO', 'PENDENTE_CRIACAO' => 'Aguardando criação da conta',
    'CONCILIACAO_MANUAL' => 'Conciliação manual',
    'PROCESSANDO', 'EM_PROCESSAMENTO' => 'Em processamento',
    'CONCLUIDO', 'CONCLUIDA' => 'Concluída', 'ERRO', 'FALHA' => 'Pendência de integração',
    default => 'Aguardando atualização',
};
?>
<div class="owner-financial-page owner-receipts-page">
<header class="panel-page-heading"><div><span>Área financeira</span><h1>Recebimentos</h1><p>Acompanhe sua conta vinculada e as pendências para receber reservas.</p></div></header>
<section class="panel-card"><h2>Situação financeira</h2>
<dl class="client-summary-list">
<div><dt>Cadastro</dt><dd><?= !empty($dados['dados_completos'])?'Completo':(($dados['situacao']??'')==='conflito'?'Divergência cadastral pendente':'Incompleto') ?></dd></div>
<div><dt>Autorização</dt><dd><?= $aceite?'Registrada':'Pendente' ?></dd></div>
<div><dt>Subconta</dt><dd><?= e($statusFinanceiro($subconta['status_local']??'NAO_INICIADA')) ?></dd></div>
<div><dt>Provisionamento</dt><dd><?= e($statusFinanceiro($provisionamento['estado']??'AGUARDANDO_CADASTRO')) ?></dd></div>
<?php if(!empty($subconta['asaas_account_id'])): ?><div><dt>Identificador da conta Asaas</dt><dd><?= e($subconta['asaas_account_id']) ?></dd></div><?php endif; ?>
<div><dt>Análise cadastral Asaas</dt><dd><?= e($statusFinanceiro($subconta['status_cadastral_asaas']??'PENDING')) ?></dd></div>
<div><dt>Situação operacional Asaas</dt><dd><?= e($statusFinanceiro($subconta['status_operacional_asaas']??null)) ?></dd></div>
<div><dt>Última sincronização</dt><dd><?= e($subconta['ultima_sincronizacao_em']??'Ainda não realizada') ?></dd></div>
</dl>
<?php if(($provisionamento['estado']??'')==='conciliacao_manual'): ?><p class="form-error">A criação da conta está em conciliação. O suporte precisa confirmar o resultado no Asaas antes de uma nova tentativa. Você pode continuar cadastrando seus imóveis.</p><?php endif; ?>
<?php if(!empty($subconta['ultimo_erro_sanitizado'])): ?><p class="form-error">Existe uma pendência de integração. Consulte o suporte para acompanhar a regularização.</p><?php endif; ?>
<p>Identificação, contato e endereço são mantidos em Dados Cadastrais. A aprovação cadastral do Asaas e a disponibilidade financeira são etapas distintas.</p>
<a class="btn btn-outline" href="<?= url('/proprietario/dados-cadastrais') ?>">Atualizar dados cadastrais</a></section>
<section class="panel-card"><h2>Histórico financeiro da conta</h2>
<?php if(empty($historico)): ?><p>Nenhuma atualização de conta registrada.</p><?php else: ?><ul><?php foreach($historico as $evento): ?><li><?= e($evento['criado_em']) ?> — <?= e($statusFinanceiro($evento['status_novo'])) ?> (<?= e($evento['origem']) ?>)</li><?php endforeach; ?></ul><?php endif; ?></section>
<section class="panel-card"><h2>Autorização</h2><p><?= e($termo) ?></p>
<?php if(!$aceite): ?><form method="post" action="<?= url('/proprietario/recebimentos/aceite') ?>"><?= csrf_field() ?><label class="owner-payment-toggle"><input type="checkbox" name="aceite_explicito" value="1" required> Li e autorizo explicitamente.</label><div class="form-actions"><button class="btn btn-primary" type="submit">Registrar autorização</button></div></form><?php else: ?><p>Autorização financeira registrada.</p><?php endif; ?></section>
</div>
