<header class="panel-navbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Alternar menu">
        <span></span><span></span><span></span>
    </button>
    <a class="panel-brand" href="<?= url('/') ?>" aria-label="Ir para a home">
        <img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug F&aacute;cil">
    </a>
    <div class="panel-navbar-actions">
        <button class="notification-button" type="button" aria-label="Notifica&ccedil;&otilde;es">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span></span>
        </button>
        <div class="user-chip">
            <span class="user-avatar"><?= e(mb_strtoupper(mb_substr(currentUser()['nome'] ?? 'U', 0, 1))) ?></span>
            <span><strong><?= e(currentUser()['nome'] ?? 'Usu&aacute;rio') ?></strong><small><?= e(ucfirst($panelRole ?? 'cliente')) ?></small></span>
        </div>
    </div>
</header>
