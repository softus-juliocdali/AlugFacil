<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Encontre chácaras para finais de semana, feriados e momentos inesquecíveis.">
    <title><?= e($title ?? config('app_name')) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('img/logo-oficial.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>?v=<?= filemtime(APP_ROOT . '/public/assets/css/style.css') ?>">
</head>
<body>
<header class="site-header" id="top">
    <div class="container navbar">
        <a class="brand" href="<?= url('/') ?>" aria-label="Alug Fácil - Início">
            <img src="<?= asset('img/logo-oficial.png') ?>" alt="Alug Fácil">
        </a>

        <button class="mobile-menu-button" type="button" aria-label="Abrir menu" aria-expanded="false" data-menu-toggle>
            <span></span><span></span><span></span>
        </button>

        <nav class="main-nav" data-mobile-menu>
            <div class="nav-links">
                <a class="<?= is_active('/') ?>" href="<?= url('/') ?>">Início</a>
                <a href="#chacaras">Chácaras</a>
                <a href="#como-funciona">Como funciona</a>
                <a href="#anuncie">Anuncie sua chácara</a>
                <a href="#sobre">Sobre nós</a>
                <a href="#contato">Contato</a>
            </div>
            <div class="nav-actions">
                <a class="btn btn-primary btn-login" href="<?= url(isLoggedIn() ? \App\Core\Auth::redirectPath() : '/login') ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg>
                    <?= isLoggedIn() ? 'Minha conta' : 'Entrar / Cadastrar' ?>
                </a>
            </div>
        </nav>
    </div>
</header>

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
<main>
