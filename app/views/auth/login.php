<section class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual">
            <span class="auth-kicker">Seu descanso começa aqui</span>
            <h1>Entre e continue planejando momentos inesquecíveis.</h1>
            <p>Gerencie reservas, favoritos e anúncios em um só lugar.</p>
        </div>
        <div class="auth-card">
            <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
            <h2>Que bom ter você aqui</h2>
            <p>Use seu e-mail e senha para acessar sua conta.</p>
            <form method="post" action="<?= url('/login') ?>">
                <?= csrf_field() ?>
                <label>E-mail<input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="voce@exemplo.com" autocomplete="email" required></label>
                <label>Senha<input type="password" name="senha" placeholder="Sua senha" autocomplete="current-password" required></label>
                <div class="auth-form-links"><a href="<?= url('/esqueceu-senha') ?>">Esqueceu a senha?</a></div>
                <button class="btn btn-primary" type="submit">Entrar</button>
            </form>
            <div class="auth-divider"><span>ou</span></div>
            <p class="auth-switch">Ainda não tem conta? <a href="<?= url('/cadastro') ?>">Cadastre-se</a></p>
            <a class="auth-owner-link" href="<?= url('/cadastro-proprietario') ?>">Quero anunciar minha chácara</a>
        </div>
    </div>
</section>
