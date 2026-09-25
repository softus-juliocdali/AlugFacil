<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Painel') ?> | Alug Facil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/panel.css') ?>?v=20260925-01">
</head>
<body class="panel-body panel-role-<?= e($panelRole) ?><?= $panelRole === 'admin' && preg_match('~^admin/(chacaras|mensalidades|reservas/index|financeiro/index)~', $view) ? ' admin-web-fix' : '' ?>">
    <?php if ($message = flash('success')): ?>
        <div class="flash flash-success" role="status" data-flash>
            <span><?= e($message) ?></span>
            <button type="button" aria-label="Fechar aviso" data-flash-close>&times;</button>
        </div>
    <?php endif; ?>
    <?php if ($message = flash('error')): ?>
        <div class="flash flash-error" role="alert" data-flash>
            <span><?= e($message) ?></span>
            <button type="button" aria-label="Fechar aviso" data-flash-close>&times;</button>
        </div>
    <?php endif; ?>
    <?php require APP_ROOT . '/app/views/layouts/navbar_panel.php'; ?>
    <aside class="panel-sidebar" data-sidebar>
        <?php require APP_ROOT . '/app/views/layouts/sidebar_' . $panelRole . '.php'; ?>
    </aside>
    <div class="sidebar-overlay" data-sidebar-overlay></div>
    <main class="panel-main<?= $panelRole === 'admin' && in_array($view, ['admin/configuracoes_financeiras/index', 'admin/financeiro/show', 'admin/asaas_eventos/index', 'admin/asaas_eventos/show'], true) ? ' admin-card-spacing' : '' ?>">
        <?php require $contentView; ?>
    </main>
    <script src="<?= asset('js/panel.js') ?>"></script>
</body>
</html>
