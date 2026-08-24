<nav class="sidebar-nav affiliate-sidebar-nav">
    <span class="sidebar-label">Área do afiliado</span>
    <a class="<?= is_active('/afiliado') ?>" href="<?= url('/afiliado') ?>"><span aria-hidden="true">&#8962;</span> Dashboard</a>
    <a class="<?= is_active('/afiliado/indicados') ?>" href="<?= url('/afiliado/indicados') ?>"><span aria-hidden="true">&#9783;</span> Indicados</a>
    <a class="<?= is_active('/afiliado/comissoes') ?>" href="<?= url('/afiliado/comissoes') ?>"><span aria-hidden="true">&#36;</span> Comissões</a>
    <a class="<?= is_active('/afiliado/perfil') ?>" href="<?= url('/afiliado/perfil') ?>"><span aria-hidden="true">&#9673;</span> Perfil</a>
    <form method="post" action="<?= url('/afiliado/logout') ?>">
        <?= csrf_field() ?>
        <button class="sidebar-logout" type="submit"><span aria-hidden="true">&#8617;</span> Sair</button>
    </form>
</nav>
