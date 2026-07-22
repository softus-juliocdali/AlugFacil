<?php
$nome = old('nome', (string) ($usuario['nome'] ?? ''));
$telefone = old('telefone', (string) ($usuario['telefone'] ?? ''));
$email = old('email', (string) ($usuario['email'] ?? ''));
?>

<header class="panel-page-heading">
    <div>
        <span>Minha conta</span>
        <h1>Meus Dados</h1>
        <p>Atualize seus dados de acesso e contato.</p>
    </div>
</header>

<section class="panel-card client-form-card">
    <form class="client-data-form" method="post" action="<?= url('/cliente/meus-dados') ?>">
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
            Senha
            <input type="password" name="senha" value="" autocomplete="new-password" placeholder="Deixe em branco para manter a senha atual">
        </label>

        <button class="btn btn-primary" type="submit">Salvar alteracoes</button>
    </form>
</section>
