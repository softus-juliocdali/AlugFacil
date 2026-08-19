<section class="auth-page">
    <div class="auth-card auth-card-compact">
        <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
        <div class="auth-icon">✉</div>
        <h2>Recupere seu acesso</h2>
        <p>Informe seu e-mail e enviaremos um link válido por 1 hora.</p>
        <form method="post" action="<?= url('/esqueceu-senha') ?>">
            <?= csrf_field() ?>
            <label>E-mail<input type="email" name="email" placeholder="voce@exemplo.com" autocomplete="email" required></label>
            <button class="btn btn-primary" type="submit">Enviar instruções</button>
        </form>
        <?php if (config('app_env') === 'development' && !empty($_SESSION['_reset_link'])): ?>
            <div class="dev-reset-link"><strong>Ambiente de desenvolvimento</strong><a href="<?= e($_SESSION['_reset_link']) ?>">Abrir link de redefinição</a></div>
            <?php unset($_SESSION['_reset_link']); ?>
        <?php endif; ?>
        <a class="auth-back" href="<?= url('/login') ?>">← Voltar para o login</a>
    </div>
</section>
