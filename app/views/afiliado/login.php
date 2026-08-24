<section class="auth-page affiliate-auth-page">
    <div class="auth-shell">
        <div class="auth-visual affiliate-auth-visual">
            <span class="auth-kicker">Programa de afiliados</span>
            <h1>Suas indicações e resultados em um só lugar.</h1>
            <p>Acompanhe proprietários, chácaras e comissões com a experiência do Alug Fácil.</p>
        </div>
        <div class="auth-card">
            <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
            <h2>Acesse sua área</h2>
            <p>Entre com o e-mail e a senha do seu cadastro de afiliado.</p>
            <form method="post" action="<?= url('/afiliado/login') ?>">
                <?= csrf_field() ?>
                <label>E-mail<input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="voce@exemplo.com" autocomplete="email" required></label>
                <label>Senha<input type="password" name="senha" placeholder="Sua senha" autocomplete="current-password" required></label>
                <button class="btn btn-primary" type="submit">Entrar</button>
            </form>
            <div class="auth-divider"><span>outros acessos</span></div>
            <p class="auth-switch"><a href="<?= url('/login') ?>">Clientes, proprietários e administradores</a></p>
        </div>
    </div>
</section>
