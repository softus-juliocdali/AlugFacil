<?php
$admin = $administrador ?? [];
$nome = old('nome', (string) ($admin['nome'] ?? ''), 'admin');
$telefone = old('telefone', (string) ($admin['telefone'] ?? ''), 'admin');
$email = old('email', (string) ($admin['email'] ?? ''), 'admin');
$status = old('status', (string) ($admin['status'] ?? 'ativo'), 'admin');
$editando = $modo === 'editar';
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1><?= $editando ? 'Editar administrador' : 'Novo administrador' ?></h1>
        <p><?= $editando ? 'Atualize dados, status e senha de acesso.' : 'Crie uma nova conta com acesso ao painel administrativo.' ?></p>
    </div>
    <a class="btn btn-outline" href="<?= url('/admin/administradores') ?>">Voltar</a>
</div>

<section class="panel-card client-form-card">
    <form class="client-data-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <label>
            Nome
            <input type="text" name="nome" value="<?= e($nome) ?>" required autocomplete="name">
        </label>

        <label>
            Telefone
            <input type="tel" name="telefone" value="<?= e($telefone) ?>" autocomplete="tel">
        </label>

        <label>
            E-mail
            <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email">
        </label>

        <label>
            Status
            <select name="status" required>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                <option value="bloqueado" <?= $status === 'bloqueado' ? 'selected' : '' ?>>Bloqueado</option>
            </select>
        </label>

        <label>
            Senha
            <input type="password" name="senha" value="" autocomplete="new-password" <?= $editando ? 'placeholder="Deixe em branco para manter a senha atual"' : 'required' ?>>
        </label>

        <label>
            Confirmar senha
            <input type="password" name="senha_confirmacao" value="" autocomplete="new-password" <?= $editando ? 'placeholder="Repita apenas se alterar a senha"' : 'required' ?>>
        </label>

        <button class="btn btn-primary" type="submit">Salvar administrador</button>
    </form>
</section>
