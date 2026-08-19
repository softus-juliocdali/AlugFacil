<?php
$formatarData = static fn (?string $data): string => $data ? date('d/m/Y', strtotime($data)) : '-';
?>
<div class="panel-page-heading">
    <div>
        <span>Minha conta</span>
        <h1>Senha do administrador</h1>
        <p>Atualize sua senha de acesso ao painel administrativo.</p>
    </div>
</div>

<section class="admin-detail-grid">
    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Dados da conta</h2>
        </div>
        <dl class="admin-detail-list">
            <div><dt>Nome</dt><dd><?= e($administrador['nome']) ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($administrador['email']) ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-pill"><?= e(ucfirst($administrador['status'])) ?></span></dd></div>
            <div><dt>Cadastro</dt><dd><?= e($formatarData($administrador['data_cadastro'])) ?></dd></div>
        </dl>
    </article>

    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Trocar senha</h2>
        </div>
        <form class="client-data-form" method="post" action="<?= url('/admin/minha-conta') ?>">
            <?= csrf_field() ?>
            <label>
                Senha atual
                <input type="password" name="senha_atual" required autocomplete="current-password">
            </label>
            <label>
                Nova senha
                <input type="password" name="senha" required autocomplete="new-password">
            </label>
            <label>
                Confirmar nova senha
                <input type="password" name="senha_confirmacao" required autocomplete="new-password">
            </label>
            <button class="btn btn-primary" type="submit">Atualizar senha</button>
        </form>
    </article>
</section>
