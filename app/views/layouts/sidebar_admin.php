<nav class="sidebar-nav">
    <span class="sidebar-label">Administracao</span>
    <a class="<?= is_active('/admin/dashboard') ?: is_active('/admin') ?>" href="<?= url('/admin/dashboard') ?>"><span>&#8962;</span> Dashboard</a>
    <a class="<?= is_active('/admin/administradores') ?>" href="<?= url('/admin/administradores') ?>"><span>&#9881;</span> Administradores</a>
    <a class="<?= is_active('/admin/proprietarios') ?>" href="<?= url('/admin/proprietarios') ?>"><span>&#9636;</span> Propriet&aacute;rios</a>
    <a class="<?= is_active('/admin/usuarios') ?>" href="<?= url('/admin/usuarios') ?>"><span>&#9812;</span> Usu&aacute;rios</a>
    <a class="<?= is_active('/admin/minha-conta') ?>" href="<?= url('/admin/minha-conta') ?>"><span>&#9919;</span> Minha conta</a>
    <a href="<?= url('/logout') ?>"><span>&#8617;</span> Sair</a>
</nav>
