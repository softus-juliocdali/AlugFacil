<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}

if (!function_exists('mb_check_encoding')) {
    function mb_check_encoding(array|string|null $value = null, ?string $encoding = null): bool
    {
        return true;
    }
}

if (!function_exists('mb_convert_encoding')) {
    function mb_convert_encoding(array|string $string, string $to_encoding, array|string|null $from_encoding = null): array|string
    {
        return $string;
    }
}

function config(string $key, mixed $default = null): mixed
{
    static $config;
    $config ??= require APP_ROOT . '/app/config/config.php';
    return $config[$key] ?? $default;
}

function app_log(string $message): void
{
    $directory = APP_ROOT . '/storage/logs';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $secrets = array_filter([
        getenv('DB_PASS') ?: null,
        getenv('ASAAS_API_KEY') ?: null,
        getenv('ASAAS_WEBHOOK_TOKEN') ?: null,
    ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    $safeMessage = str_replace($secrets, '[REDACTED]', $message);
    @error_log('[' . date('Y-m-d H:i:s') . '] ' . $safeMessage . PHP_EOL, 3, $directory . '/app.log');
}

function url(string $path = ''): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $base = preg_replace('#/public$#', '', $base) ?? $base;
    $base = $base === '.' ? '' : $base;
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function set_old(array $data): void
{
    $_SESSION['_old_next'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old'], $_SESSION['_old_next']);
}

function prepare_old_input_flash(): void
{
    unset($_SESSION['_old']);

    if (!isset($_SESSION['_old_next']) || !is_array($_SESSION['_old_next'])) {
        unset($_SESSION['_old_next']);
        return;
    }

    $_SESSION['_old'] = $_SESSION['_old_next'];
    unset($_SESSION['_old_next']);
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(string $redirectPath = '/login'): void
{
    $token = $_POST['_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        flash('error', 'Sua sessão expirou. Tente novamente.');
        header('Location: ' . url($redirectPath));
        exit;
    }
}

function isLoggedIn(): bool
{
    return \App\Core\Auth::check();
}

function currentUser(): ?array
{
    return \App\Core\Auth::user();
}

function currentAffiliate(): ?array
{
    return \App\Core\AffiliateAuth::user();
}

function requireLogin(): void
{
    \App\Core\Auth::requireLogin();
}

function requireRole(string $role): void
{
    \App\Core\Auth::requireRole($role);
}

function render_view(string $view, array $data = [], string $layout = 'public'): void
{
    $viewFile = resolve_view_file($view);
    if (!is_file($viewFile)) {
        throw new RuntimeException("View nao encontrada: {$view}. Caminho esperado: {$viewFile}");
    }

    extract($data, EXTR_SKIP);

    if ($layout === 'panel') {
        $contentView = $viewFile;
        require resolve_view_file('layouts/panel_layout');
        return;
    }

    require resolve_view_file('layouts/header_public');
    require $viewFile;
    require resolve_view_file('layouts/footer_public');
}

function resolve_view_file(string $view): string
{
    $relative = str_replace('\\', '/', trim($view, '/')) . '.php';
    $baseCandidates = [
        APP_ROOT . '/app/views',
        APP_ROOT . '/app/Views',
    ];

    foreach ($baseCandidates as $base) {
        $candidate = $base . '/' . $relative;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    foreach ($baseCandidates as $base) {
        $resolved = resolve_case_insensitive_path($base, $relative);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return $baseCandidates[0] . '/' . $relative;
}

function resolve_case_insensitive_path(string $base, string $relative): ?string
{
    if (!is_dir($base)) {
        return null;
    }

    $current = rtrim($base, '/\\');
    foreach (explode('/', $relative) as $part) {
        $items = scandir($current);
        if ($items === false) {
            return null;
        }

        $match = null;
        foreach ($items as $item) {
            if (strcasecmp($item, $part) === 0) {
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $current .= '/' . $match;
    }

    return is_file($current) ? $current : null;
}

function is_active(string $path): string
{
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return rtrim((string) $current, '/') === rtrim(url($path), '/') ? 'is-active' : '';
}
