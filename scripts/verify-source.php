<?php
declare(strict_types=1);

// Read-only verification: no application bootstrap, database or gateway access.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$expected = [];
foreach (file($root . '/deploy/source.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (!preg_match('~^([a-f0-9]{64})  ((?:app|public|scripts|database|deliverables)/[^\r\n]+)$~D', $line, $m) || str_contains($m[2], '..')) {
        throw new RuntimeException('Invalid source manifest');
    }
    $expected[$m[2]] = $m[1];
}
if (!$expected) throw new RuntimeException('Empty source manifest');
$errors = [];
foreach ($expected as $file => $hash) {
    $path = $root . '/' . $file;
    if (is_link($path) || !is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) $errors[] = $file;
}
foreach (['app', 'public', 'scripts', 'database'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_starts_with($relative, 'public/assets/uploads/') || $relative === 'public/error_log') continue;
        if (($file->isFile() || $file->isLink()) && !isset($expected[$relative])) $errors[] = $relative;
    }
}
$errors = array_values(array_unique($errors));
echo json_encode(['verified_files' => count($expected), 'code_divergences' => count($errors), 'files' => $errors], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($errors ? 1 : 0);
