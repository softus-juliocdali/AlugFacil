<section class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual auth-visual-owner">
            <span class="auth-kicker">Anuncie no Alug Fácil</span>
            <h1>Transforme sua propriedade em novas oportunidades.</h1>
            <p>Cadastre-se para publicar anúncios e acompanhar suas reservas.</p>
        </div>
        <div class="auth-card auth-card-wide">
            <a class="auth-logo" href="<?= url('/') ?>"><img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil"></a>
            <h2>Cadastro de proprietário</h2>
            <p>Seu perfil será criado e ficará pronto para receber seus anúncios.</p>
            <form method="post" action="<?= url('/cadastro-proprietario') ?>">
                <?= csrf_field() ?>
                <?php $affiliateCodeError = flash('affiliate_code_error'); ?>
                <label>Nome completo<input type="text" name="nome" value="<?= e(old('nome')) ?>" autocomplete="name" required minlength="2"></label>
                <div class="auth-fields-row">
                    <label>Telefone<input type="tel" name="telefone" value="<?= e(old('telefone')) ?>" placeholder="(11) 99999-9999" autocomplete="tel"></label>
                    <label>E-mail<input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required></label>
                </div>
                <div class="auth-fields-row">
                    <label>Senha<input type="password" name="senha" minlength="6" autocomplete="new-password" required></label>
                    <label>Confirmar senha<input type="password" name="senha_confirmacao" minlength="6" autocomplete="new-password" required></label>
                </div>
                <?php $affiliateCodeValue = ($affiliateCode ?? '') !== '' ? (string) $affiliateCode : old('codigo_afiliado'); ?>
                <label>
                    Possui código?
                    <input type="text" name="codigo_afiliado" value="<?= e($affiliateCodeValue) ?>" placeholder="AF0001" maxlength="32" autocomplete="off" aria-describedby="affiliate-code-help<?= $affiliateCodeError ? ' affiliate-code-error' : '' ?>">
                    <small id="affiliate-code-help">Informe o código do afiliado, se possuir.</small>
                    <?php if ($affiliateCodeError): ?><small id="affiliate-code-error" class="field-error" role="alert"><?= e($affiliateCodeError) ?></small><?php endif; ?>
                </label>
                <button class="btn btn-primary" type="submit">Cadastrar como proprietário</button>
            </form>
            <p class="auth-switch">Já possui conta? <a href="<?= url('/login') ?>">Entrar</a></p>
        </div>
    </div>
</section>
