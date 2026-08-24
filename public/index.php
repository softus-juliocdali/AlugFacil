<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/helpers/functions.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $parts[0] = strtolower($parts[0]);
    $file = APP_ROOT . '/app/' . implode('/', $parts) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Falhas de configuracao devem ser registradas, nunca exibidas ao visitante.
ini_set('display_errors', '0');
error_reporting(E_ALL);
$config = require APP_ROOT . '/app/config/config.php';
date_default_timezone_set($config['timezone']);
ini_set('display_errors', $config['app_env'] === 'development' ? '1' : '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $config['app_env'] === 'production' && $isHttps,
    'samesite' => 'Lax',
]);
session_name($config['session_name']);
session_start();
prepare_old_input_flash();

$now = time();
if (isset($_SESSION['_last_activity']) && $now - (int) $_SESSION['_last_activity'] > 7200) {
    $expiredAffiliateOnly = isset($_SESSION['affiliate']) && !isset($_SESSION['user']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', $now - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
    prepare_old_input_flash();
    session_regenerate_id(true);
    flash('error', 'Sua sessao expirou. Faca login novamente.');
    header('Location: ' . url($expiredAffiliateOnly ? '/afiliado/login' : '/login'));
    exit;
}
$_SESSION['_last_activity'] = $now;

set_exception_handler(static function (Throwable $exception) use ($config): void {
    app_log(sprintf(
        '%s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if ($config['app_env'] === 'development') {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h1>Erro interno</h1>';
        echo '<pre>' . e($exception::class . ': ' . $exception->getMessage()) . "\n";
        echo e($exception->getFile() . ':' . $exception->getLine()) . "\n\n";
        echo e($exception->getTraceAsString()) . '</pre>';
        return;
    }

    http_response_code(500);

    try {
        render_view('public/404', [
            'title' => 'Nao foi possivel concluir a solicitacao',
            'message' => 'Nao foi possivel concluir sua solicitacao agora. Tente novamente em instantes.',
        ]);
    } catch (Throwable $fallbackException) {
        app_log($fallbackException::class . ': ' . $fallbackException->getMessage());
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Erro interno</title></head><body>';
        echo '<h1>Nao foi possivel concluir a solicitacao</h1>';
        echo '<p>Tente novamente em instantes ou contate o suporte.</p>';
        echo '</body></html>';
    }
});

use App\Core\Router;

$router = new Router();
$registerRoutes = require APP_ROOT . '/app/config/routes.php';
$registerRoutes($router, $config);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
