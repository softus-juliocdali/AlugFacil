<section class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual auth-visual-client">
            <span class="auth-kicker">Crie sua conta grátis</span>
            <h1>Encontre o lugar perfeito para o próximo encontro.</h1>
            <p>Salve favoritos, acompanhe reservas e alugue com tranquilidade.</p>
        </div>
        <div class="auth-card auth-card-wide">
            <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
            <h2>Cadastro de cliente</h2>
            <p>Preencha seus dados para começar.</p>
            <form method="post" action="<?= url('/cadastro') ?>">
                <?= csrf_field() ?>
                <label>Nome completo<input type="text" name="nome" value="<?= e(old('nome')) ?>" autocomplete="name" required minlength="2"></label>
                <div class="auth-fields-row">
                    <label>Telefone<input type="tel" name="telefone" value="<?= e(old('telefone')) ?>" placeholder="(11) 99999-9999" autocomplete="tel"></label>
                    <label>E-mail<input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required></label>
                </div>
                <div class="auth-fields-row">
                    <label>Senha<input type="password" name="senha" minlength="6" autocomplete="new-password" required></label>
                    <label>Confirmar senha<input type="password" name="senha_confirmacao" minlength="6" autocomplete="new-password" required></label>
                </div>
                <button class="btn btn-primary" type="submit">Criar minha conta</button>
            </form>
            <p class="auth-switch">Já possui conta? <a href="<?= url('/login') ?>">Entrar</a></p>
        </div>
    </div>
</section>
