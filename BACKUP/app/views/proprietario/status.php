<?php
$status = (string) $proprietario['status'];
$titulo = match ($status) {
    'pendente' => 'Cadastro em an&aacute;lise',
    'rejeitado' => 'Cadastro rejeitado',
    default => 'Cadastro bloqueado',
};
?>
<div class="panel-page-heading">
    <div>
        <span>Cadastro de propriet&aacute;rio</span>
        <h1><?= $titulo ?></h1>
        <p>As fun&ccedil;&otilde;es operacionais permanecem indispon&iacute;veis enquanto este status estiver vigente.</p>
    </div>
</div>

<section class="panel-card">
    <div class="panel-card-heading"><h2>Situa&ccedil;&atilde;o atual</h2></div>
    <div class="detail-grid">
        <div><dt>Status</dt><dd><span class="status-pill"><?= e(ucfirst($status)) ?></span></dd></div>
        <div><dt>Propriet&aacute;rio</dt><dd><?= e($proprietario['nome']) ?></dd></div>
        <div><dt>E-mail</dt><dd><?= e($proprietario['email']) ?></dd></div>
        <?php if (!empty($proprietario['motivo_status'])): ?>
            <div><dt>Motivo</dt><dd><?= e($proprietario['motivo_status']) ?></dd></div>
        <?php endif; ?>
    </div>
    <div class="form-actions">
        <a class="btn btn-primary" href="<?= url('/proprietario/dados-cadastrais') ?>">Atualizar meus dados</a>
        <a class="btn btn-outline" href="<?= url('/logout') ?>">Sair</a>
    </div>
</section>
