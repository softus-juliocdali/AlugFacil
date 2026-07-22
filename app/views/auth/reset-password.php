<section class="auth-page">
    <div class="auth-card auth-card-compact">
        <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
        <div class="auth-icon">✓</div>
        <h2>Crie uma nova senha</h2>
        <p>Escolha uma senha com pelo menos 6 caracteres.</p>
        <form method="post" action="<?= url('/redefinir-senha') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label>Nova senha<input type="password" name="senha" minlength="6" autocomplete="new-password" required></label>
            <label>Confirmar nova senha<input type="password" name="senha_confirmacao" minlength="6" autocomplete="new-password" required></label>
            <button class="btn btn-primary" type="submit">Salvar nova senha</button>
        </form>
    </div>
</section>
