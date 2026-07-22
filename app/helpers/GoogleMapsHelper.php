<?php

declare(strict_types=1);

namespace App\Helpers;

final class GoogleMapsHelper
{
    private string $apiKey;

    public function __construct()
    {
        $config = require APP_ROOT . '/app/config/apis.php';
        $maps = $config['maps'] ?? [];

        $this->apiKey = trim((string) ($maps['api_key'] ?? ''));
    }

    public function configurado(): bool
    {
        return $this->apiKey !== '';
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function embedUrl(float|string|null $latitude, float|string|null $longitude, int $zoom = 15): ?string
    {
        if (!$this->configurado() || !$this->coordenadasValidas($latitude, $longitude)) {
            return null;
        }

        return 'https://www.google.com/maps/embed/v1/view?' . http_build_query([
            'key' => $this->apiKey,
            'center' => $this->normalizarNumero($latitude) . ',' . $this->normalizarNumero($longitude),
            'zoom' => max(1, min(21, $zoom)),
            'maptype' => 'roadmap',
        ]);
    }

    public function coordenadasValidas(float|string|null $latitude, float|string|null $longitude): bool
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return false;
        }

        $lat = filter_var(str_replace(',', '.', (string) $latitude), FILTER_VALIDATE_FLOAT);
        $lng = filter_var(str_replace(',', '.', (string) $longitude), FILTER_VALIDATE_FLOAT);

        return $lat !== false && $lng !== false && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function normalizarNumero(float|string $numero): string
    {
        return rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $numero), 7, '.', ''), '0'), '.');
    }
}
