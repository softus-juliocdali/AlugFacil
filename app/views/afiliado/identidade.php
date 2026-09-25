<section class="container"><h1>Conta para suas reservas</h1>
<?php if($principal): ?><p>Vínculo com <?= e($principal['email']) ?>. Seu histórico de afiliado permanece separado.</p><a class="btn btn-primary" href="<?= url('/cliente/historico') ?>">Minhas reservas</a>
<?php else: ?><p>Para reservar, vincule sua conta de afiliado à sua conta principal do AlugFácil. O vínculo exige as duas senhas e preserva indicações e comissões.</p>
<form method="post" action="<?= url('/afiliado/identidade') ?>"><?= csrf_field() ?>
<label>Senha do afiliado<input type="password" name="senha_afiliado" autocomplete="current-password" required></label>
<label>E-mail da conta principal<input type="email" name="email_principal" autocomplete="username" required></label>
<label>Senha da conta principal<input type="password" name="senha_principal" required autocomplete="current-password"></label>
<label><input type="checkbox" name="confirmar_vinculo" value="1" required> Confirmo que as duas contas são minhas e autorizo o vínculo.</label>
<button class="btn btn-primary" type="submit">Vincular minhas contas</button></form>
<p>Ainda não tem uma conta principal? <a href="<?= url('/cadastro') ?>">Crie sua conta</a> e volte a esta página para vinculá-la.</p>
<?php endif; ?></section>
