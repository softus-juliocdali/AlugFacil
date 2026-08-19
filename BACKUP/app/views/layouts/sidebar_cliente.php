<?php
$clientePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$favoritosAtivo = str_contains($clientePath, '/cliente/favoritos');
$historicoAtivo = str_contains($clientePath, '/cliente/historico') || str_contains($clientePath, '/cliente/reserva/');
?>
<nav class="sidebar-nav">
    <span class="sidebar-label">Minha conta</span>
    <a class="<?= $favoritosAtivo ? 'is-active' : '' ?>" href="<?= url('/cliente/favoritos') ?>"><span>&hearts;</span> Favoritos</a>
    <a class="<?= $historicoAtivo ? 'is-active' : '' ?>" href="<?= url('/cliente/historico') ?>"><span>&#9635;</span> Historico</a>
    <a class="<?= is_active('/cliente/meus-dados') ?>" href="<?= url('/cliente/meus-dados') ?>"><span>&#9817;</span> Meus Dados</a>
    <a href="<?= url('/logout') ?>"><span>&#8617;</span> Sair</a>
</nav>
