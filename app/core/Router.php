<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $path = '/' . trim($path, '/');
        $this->routes[$method][$path === '/' ? '/' : rtrim($path, '/')] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($basePath !== '/' && $basePath !== '.' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $handler = $this->routes[strtoupper($method)][$path] ?? null;
        $parameters = [];

        if ($handler === null) {
            foreach ($this->routes[strtoupper($method)] ?? [] as $route => $candidate) {
                if (!str_contains($route, '{')) {
                    continue;
                }

                $parameterNames = [];
                $pattern = preg_replace_callback(
                    '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
                    static function (array $matches) use (&$parameterNames): string {
                        $parameterNames[] = $matches[1];
                        return '([^/]+)';
                    },
                    preg_quote($route, '#')
                );

                if ($pattern !== null && preg_match('#^' . $pattern . '$#', $path, $matches) === 1) {
                    array_shift($matches);
                    $parameters = array_map('urldecode', $matches);
                    $handler = $candidate;
                    break;
                }
            }
        }

        if ($handler === null) {
            http_response_code(404);
            render_view('public/404', ['title' => 'Página não encontrada']);
            return;
        }

        if (is_array($handler)) {
            [$controller, $action] = $handler;
            (new $controller())->{$action}(...$parameters);
            return;
        }

        $handler(...$parameters);
    }
}
