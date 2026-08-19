<?php
$nome = old('nome', (string) ($usuario['nome'] ?? ''), 'proprietario');
$telefone = old('telefone', (string) ($usuario['telefone'] ?? ''), 'proprietario');
$cpfAtual = (string) ($proprietario['cpf'] ?? '');
$cpf = old('cpf', strlen($cpfAtual) === 11
    ? substr($cpfAtual, 0, 3) . '.' . substr($cpfAtual, 3, 3) . '.' . substr($cpfAtual, 6, 3) . '-' . substr($cpfAtual, 9, 2)
    : $cpfAtual, 'proprietario');
$email = old('email', (string) ($usuario['email'] ?? ''), 'proprietario');
?>

<header class="panel-page-heading">
    <div>
        <span>Minha conta</span>
        <h1>Dados Cadastrais</h1>
        <p>Atualize seus dados de acesso e contato como propriet&aacute;rio.</p>
    </div>
</header>

<section class="panel-card client-form-card">
    <form class="client-data-form" method="post" action="<?= url('/proprietario/dados-cadastrais') ?>">
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
            CPF
            <input type="text" name="cpf" value="<?= e($cpf) ?>" required inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" data-cpf-mask>
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
