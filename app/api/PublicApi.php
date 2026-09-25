<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Chacara;
use DateTimeImmutable;
use Throwable;

final class PublicApi
{
    public static function path(string $uri, string $script): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        $bases = array_unique([$base, preg_replace('~/public$~', '', $base)]);
        foreach ($bases as $candidate) {
            if ($candidate !== '' && str_starts_with($path, $candidate . '/api/v1')) {
                return substr($path, strlen($candidate));
            }
        }
        return $path;
    }

    public static function matches(string $uri, string $script): bool
    {
        return preg_match('~^/api/v1(?:/|$)~', self::path($uri, $script)) === 1;
    }

    public function run(): void
    {
        ini_set('display_errors', '0');
        $requestId = bin2hex(random_bytes(12));
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException('API runtime error.', 0, $severity, $file, $line);
        });
        $status = 200;
        $headers = [];
        try {
            [$data, $meta] = $this->dispatch(
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                self::path($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['SCRIPT_NAME'] ?? ''),
                $_GET,
            );
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && rtrim(self::path($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/') === '/api/v1/auth/cadastro') $status = 201;
            $body = ['data' => $data, 'meta' => $meta + ['request_id' => $requestId]];
        } catch (ApiException $exception) {
            $status = $exception->status;
            if ($status === 405) {
                $headers['Allow'] = str_contains(self::path($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/v1/auth/') ? 'POST' : 'GET';
            }
            $body = ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage(), 'fields' => (object) $exception->fields], 'request_id' => $requestId];
        } catch (Throwable) {
            $status = 500;
            // No input, query, exception details or configuration values in logs.
            error_log('[public-api] INTERNAL_ERROR request_id=' . $requestId);
            $body = ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Nao foi possivel concluir a solicitacao.', 'fields' => (object) []], 'request_id' => $requestId];
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        http_response_code($status);
        header_remove('Location');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function dispatch(string $method, string $path, array $query): array
    {
        $path = rtrim($path, '/');
        if (MobileAuthApi::handles($path)) return (new MobileAuthApi())->dispatch($method, $path, $query);
        $known = in_array($path, ['/api/v1/health', '/api/v1/home', '/api/v1/imoveis'], true)
            || preg_match('~^/api/v1/imoveis/([^/]+)(/disponibilidade)?$~D', $path, $match);
        if (!$known) {
            throw new ApiException(404, 'NOT_FOUND', 'Rota nao encontrada.');
        }
        if ($method !== 'GET') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metodo nao permitido.');
        }
        if (strlen($_SERVER['QUERY_STRING'] ?? '') > 8192) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Consulta excede o limite permitido.');
        }
        if ($path === '/api/v1/health') {
            PublicQuery::keys($query, []);
            return [['status' => 'ok', 'api_version' => 'v1'], []];
        }

        // Validate before opening a database connection; no Web session is started.
        $listing = in_array($path, ['/api/v1/home', '/api/v1/imoveis'], true);
        if ($listing) {
            $input = PublicQuery::listing($query);
        } else {
            $id = PublicQuery::id($match[1]);
            $availability = isset($match[2]) && $match[2] !== '';
            if ($availability) {
                [$start, $end] = PublicQuery::availability($query);
            } else {
                PublicQuery::keys($query, []);
            }
        }
        $config = require APP_ROOT . '/app/config/config.php';
        date_default_timezone_set($config['timezone']);
        $serializer = new PublicPropertySerializer($config['app_url'], $config['app_env']);
        $model = new Chacara();
        if ($listing) {
            $result = $model->buscarDisponiveisPaginados($input['filters'], $input['page'], $input['per_page']);
            $items = array_map([$serializer, 'card'], $result['itens']);
            $pagination = ['page' => $input['page'], 'per_page' => $input['per_page'], 'total' => $result['total'], 'total_pages' => (int) ceil($result['total'] / $input['per_page'])];
            $data = $path === '/api/v1/home' ? [
                'hero' => $serializer->hero(),
                'tipos_imovel' => [['id' => 'chacara', 'nome' => 'Chácara'], ['id' => 'sitio', 'nome' => 'Sítio'], ['id' => 'area_lazer', 'nome' => 'Área de lazer']],
                'imoveis' => $items,
            ] : $items;
            return [$data, ['pagination' => $pagination]];
        }

        $property = $model->buscarPerfil($id);
        if ($property === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Imovel nao encontrado.');
        }
        if ($availability) {
            // Existing model range is inclusive. API follows [check-in, check-out).
            $lastDay = (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d');
            $rows = $model->buscarDatasIndisponiveis($id, $start, $lastDay);
            $days = [];
            foreach ($rows as $row) {
                $date = (string) $row['data'];
                // A manual block takes precedence when both sources occupy a date.
                if (!isset($days[$date]) || $row['status'] === 'bloqueado') {
                    $days[$date] = ['data' => $date, 'status' => $row['status'] === 'bloqueado' ? 'bloqueado' : 'reservado'];
                }
            }
            ksort($days);
            return [[
                'imovel_id' => $id, 'data_inicio' => $start, 'data_fim' => $end,
                'intervalo' => '[data_inicio,data_fim)', 'disponivel' => $days === [],
                'datas_indisponiveis' => array_values($days),
            ], ['timezone' => 'America/Sao_Paulo', 'consultado_em' => (new DateTimeImmutable())->format(DATE_ATOM)]];
        }
        return [$serializer->detail($property, $model->buscarFotos($id), $model->buscarAvaliacoesAtivas($id)), []];
    }
}
