<nav class="sidebar-nav">
    <span class="sidebar-label">Gestao</span>
    <a class="<?= is_active('/proprietario/dashboard') ?: is_active('/proprietario') ?>" href="<?= url('/proprietario/dashboard') ?>"><span>&#8962;</span> Dashboard</a>
    <a class="<?= is_active('/proprietario/chacaras') ?>" href="<?= url('/proprietario/chacaras') ?>"><span>&#9636;</span> Minhas Ch&aacute;caras</a>
    <a class="<?= is_active('/proprietario/disponibilidade') ?>" href="<?= url('/proprietario/disponibilidade') ?>"><span>&#9638;</span> Disponibilidade</a>
    <a class="<?= is_active('/proprietario/faturamento') ?>" href="<?= url('/proprietario/faturamento') ?>"><span>&#8599;</span> Faturamento</a>
    <a class="<?= is_active('/proprietario/mensalidades') ?>" href="<?= url('/proprietario/mensalidades') ?>"><span>&#36;</span> Mensalidades</a>
    <a class="<?= is_active('/proprietario/recebimentos') ?>" href="<?= url('/proprietario/recebimentos') ?>"><span>&#36;</span> Recebimentos</a>
    <span class="sidebar-label">Conta</span>
    <a class="<?= is_active('/proprietario/dados-cadastrais') ?>" href="<?= url('/proprietario/dados-cadastrais') ?>"><span>&#9812;</span> Dados Cadastrais</a>
    <a href="<?= url('/logout') ?>"><span>&#8617;</span> Sair</a>
</nav>
