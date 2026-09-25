<?php

declare(strict_types=1);

namespace App\Api;

final class PublicPropertySerializer
{
    private string $baseUrl;

    public function __construct(string $baseUrl, string $environment)
    {
        $parts = parse_url($baseUrl);
        $host = $parts['host'] ?? '';
        $privateIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $host) === 1;
        $localHttp = in_array($environment, ['development', 'test'], true)
            && ($parts['scheme'] ?? '') === 'http'
            && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || $privateIpv4);
        if (!$parts || empty($parts['host']) || (($parts['scheme'] ?? '') !== 'https' && !$localHttp)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl)) {
            throw new \RuntimeException('Invalid public asset origin.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Explicit allowlist: never merge a model row into the response. */
    public function card(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'tipo_imovel' => (string) $row['tipo_imovel'],
            'cidade' => (string) $row['cidade'],
            'regiao' => $row['regiao'] === null ? null : (string) $row['regiao'],
            'preco' => ['diaria_centavos' => PublicQuery::cents((string) $row['valor_diaria']), 'moeda' => 'BRL', 'unidade' => 'diaria'],
            'imagem' => $this->image((string) ($row['foto'] ?? $row['foto_principal'] ?? '')),
            'avaliacoes' => ['media' => (float) ($row['avaliacao'] ?? 0), 'quantidade' => (int) ($row['total_avaliacoes'] ?? 0)],
        ];
    }

    public function detail(array $row, array $photos, array $reviews): array
    {
        $row['avaliacao'] = $reviews === [] ? 0 : round(array_sum(array_column($reviews, 'nota')) / count($reviews), 1);
        $row['total_avaliacoes'] = count($reviews);
        $row['foto'] = $photos[0]['caminho_foto'] ?? $row['foto_principal'] ?? '';
        $gallery = array_map(fn (array $photo): array => $this->image((string) $photo['caminho_foto']), $photos);
        return $this->card($row) + [
            'descricao' => (string) ($row['descricao'] ?? ''),
            'localizacao' => [
                'cidade' => (string) $row['cidade'],
                'estado' => isset($row['estado']) ? (string) $row['estado'] : null,
                'regiao' => isset($row['regiao']) ? (string) $row['regiao'] : null,
                'endereco' => (string) $row['endereco'],
                'latitude' => $this->coordinate($row['latitude'] ?? null, 90),
                'longitude' => $this->coordinate($row['longitude'] ?? null, 180),
            ],
            'horarios' => [
                'checkin_inicio' => $this->time($row['checkin_hora_inicial'] ?? null),
                'checkin_fim' => $this->time($row['checkin_hora_final'] ?? null),
                'checkout_inicio' => $this->time($row['checkout_hora_inicial'] ?? null),
                'checkout_fim' => $this->time($row['checkout_hora_final'] ?? null),
            ],
            'galeria' => $gallery ?: [$this->image((string) ($row['foto_principal'] ?? ''))],
        ];
    }

    public function image(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }
        // Only public catalog images. Never convert a filesystem path or foreign URL.
        if (preg_match('~^(?:uploads/chacaras|img)/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~D', $path)) {
            $root = realpath(APP_ROOT . '/public/assets');
            $file = realpath(APP_ROOT . '/public/assets/' . $path);
            if ($root !== false && $file !== false && is_file($file)
                && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/')) {
                return ['url' => $this->baseUrl . '/assets/' . $path, 'ilustrativa' => preg_match('~^img/chacara-[1-6]\.jpg$~D', $path) === 1];
            }
        }
        return ['url' => $this->baseUrl . '/assets/img/chacara-1.jpg', 'ilustrativa' => true];
    }

    public function hero(): array
    {
        return [
            'titulo' => 'Encontre a chácara perfeita para relaxar com quem você ama',
            'descricao' => 'Encontre opções para finais de semana, feriados e momentos inesquecíveis.',
            'imagem_url' => $this->baseUrl . '/assets/img/hero-chacara.png',
            'logo_url' => $this->baseUrl . '/assets/img/logo-oficial.png',
        ];
    }

    private function coordinate(mixed $value, int $limit): ?float
    {
        return $value !== null && is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]/', $value) ? substr($value, 0, 5) : null;
    }
}
