<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
    require APP_ROOT . '/app/helpers/functions.php';
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $parts = explode('\\', substr($class, 4));
        $parts[0] = strtolower($parts[0]);
        require APP_ROOT . '/app/' . implode('/', $parts) . '.php';
    });
}

function apiCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo '[OK] ' . $label . PHP_EOL;
}

/** Real HTTP requests to the unmodified production entry point, on loopback only. */
final class PublicApiTestServer
{
    private mixed $process = null;
    private array $pipes = [];
    private string $directory;
    private string $origin;
    private ?string $captureFile = null;

    public function __construct(array $environment = [])
    {
        if (getenv('ALUGFACIL_API_CAPTURE') === '1') {
            $suite = basename($_SERVER['SCRIPT_FILENAME'], '.php');
            if (!in_array($suite, ['public_api_http_test', 'public_api_postgresql_test'], true)) {
                throw new RuntimeException('Unknown HTTP capture suite.');
            }
            $this->captureFile = sys_get_temp_dir() . '/alugfacil-' . $suite . '.jsonl';
            if (file_put_contents($this->captureFile, '') === false) {
                throw new RuntimeException('Cannot initialize HTTP contract capture.');
            }
        }
        $this->directory = sys_get_temp_dir() . '/alugfacil-api-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create isolated HTTP test directory.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate loopback test port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->origin = 'http://' . $address;
        $env = array_merge(getenv(), ['APP_ENV' => 'test', 'APP_URL' => 'https://catalogo.example.test'], $environment);
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $this->directory,
            '-S', $address, '-t', APP_ROOT . '/public', APP_ROOT . '/public/router.php'];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout.log', 'a'],
            2 => ['file', $this->directory . '/stderr.log', 'a']], $this->pipes, APP_ROOT, $env, ['bypass_shell' => true]);
        if (!is_resource($this->process)) {
            $this->close();
            throw new RuntimeException('Cannot start HTTP test server.');
        }
        $ready = false;
        for ($i = 0; $i < 60; $i++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (!$ready) {
            $this->close();
            throw new RuntimeException('HTTP server did not become ready.');
        }
    }

    public function request(string $path, string $method = 'GET', array $headers = [], ?string $body = null): array
    {
        $responseHeaders = [];
        $curl = curl_init($this->origin . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))][] = trim($value);
                }
                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        if ($raw === false) {
            throw new RuntimeException('HTTP transport failed.');
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'json' => json_decode($raw, true)];
    }

    public function api(string $path, int $status = 200, string $method = 'GET', array $headers = []): array
    {
        $result = $this->request($path, $method, $headers);
        apiCheck($result['status'] === $status, "$method $path HTTP $status");
        apiCheck(str_starts_with($result['headers']['content-type'][0] ?? '', 'application/json')
            && is_array($result['json'])
            && !isset($result['headers']['location']) && !isset($result['headers']['set-cookie']), 'JSON without redirect or session cookie');
        if ($status >= 400) {
            apiCheck(array_keys($result['json']) === ['error', 'request_id']
                && array_keys($result['json']['error']) === ['code', 'message', 'fields'], 'Consistent error contract');
        }
        if ($this->captureFile !== null) {
            // Only public JSON from controlled fixtures; never headers/cookies.
            $written = file_put_contents($this->captureFile, json_encode([
                'path' => $path, 'method' => $method, 'status' => $result['status'],
                'body' => json_decode($result['body'], false, 512, JSON_THROW_ON_ERROR),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($written === false) {
                throw new RuntimeException('Cannot capture HTTP contract response.');
            }
        }
        return $result;
    }

    public function sessionFileCount(): int
    {
        return count(glob($this->directory . '/sess_*') ?: []);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        // Only files created inside this exact, randomly allocated test directory.
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
