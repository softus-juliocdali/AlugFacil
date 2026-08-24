<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Affiliate;

final class AffiliateAttributionService
{
    public const COOKIE_NAME = 'alugfacil_affiliate_ref';
    public const TTL_SECONDS = 30 * 24 * 60 * 60;

    private Affiliate $affiliates;
    private int $now;
    private bool $emitCookieHeader;
    private string $secret;

    public function __construct(?Affiliate $affiliates = null, ?int $now = null, bool $emitCookieHeader = true)
    {
        $this->affiliates = $affiliates ?? new Affiliate();
        $this->now = $now ?? time();
        $this->emitCookieHeader = $emitCookieHeader;
        $this->secret = (string) config('affiliate_cookie_secret');
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function codeHasValidFormat(string $code): bool
    {
        return preg_match('/^AF[0-9]{4,}$/', self::normalizeCode($code)) === 1;
    }

    public function captureFromQuery(?string $reference): ?array
    {
        $pending = $this->readPending();
        if ($pending['state'] === 'valid') {
            return $pending['attribution'];
        }
        if ($pending['state'] === 'unavailable') {
            return null;
        }
        if (in_array($pending['state'], ['invalid', 'expired'], true)) {
            $this->clearPending();
        }

        $code = self::normalizeCode((string) $reference);
        if (!self::codeHasValidFormat($code)) {
            return null;
        }
        $affiliate = $this->affiliates->findActiveByCode($code);
        if ($affiliate === null) {
            return null;
        }

        $attribution = $this->attribution($affiliate, 'link', $this->now);
        $this->storePending($attribution);
        return $attribution;
    }

    /** @return array{attribution: ?array, error: ?string} */
    public function resolveForRegistration(string $manualCode): array
    {
        $pending = $this->readPending();
        if ($pending['state'] === 'valid') {
            return ['attribution' => $pending['attribution'], 'error' => null];
        }
        if (in_array($pending['state'], ['unavailable', 'expired'], true)) {
            if ($pending['state'] === 'expired') {
                $this->clearPending();
            }
            return ['attribution' => null, 'error' => null];
        }
        if ($pending['state'] === 'invalid') {
            $this->clearPending();
        }

        $code = self::normalizeCode($manualCode);
        if ($code === '') {
            return ['attribution' => null, 'error' => null];
        }
        if (!self::codeHasValidFormat($code)) {
            return ['attribution' => null, 'error' => 'Código de afiliado inválido.'];
        }
        $affiliate = $this->affiliates->findActiveByCode($code);
        if ($affiliate === null) {
            return ['attribution' => null, 'error' => 'Código de afiliado inválido.'];
        }

        return [
            'attribution' => $this->attribution($affiliate, 'codigo', $this->now),
            'error' => null,
        ];
    }

    public function codeForForm(?array $attribution): string
    {
        return self::normalizeCode((string) ($attribution['codigo'] ?? ''));
    }

    public function clearPending(): void
    {
        if ($this->emitCookieHeader && !headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires' => $this->now - 3600,
                'path' => '/',
                'secure' => $this->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /** @return array{state: string, attribution: ?array} */
    public function readPending(): array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || $token === '') {
            return ['state' => 'absent', 'attribution' => null];
        }
        $payload = $this->decode($token);
        if ($payload === null) {
            return ['state' => 'invalid', 'attribution' => null];
        }
        $attributedAt = (int) ($payload['a'] ?? 0);
        if ($attributedAt <= 0 || $this->now >= $attributedAt + self::TTL_SECONDS) {
            return ['state' => 'expired', 'attribution' => null];
        }
        $code = self::normalizeCode((string) ($payload['c'] ?? ''));
        if (($payload['s'] ?? null) !== 'link' || !self::codeHasValidFormat($code)) {
            return ['state' => 'invalid', 'attribution' => null];
        }
        $affiliate = $this->affiliates->findActiveByCode($code);
        if ($affiliate === null) {
            return ['state' => 'unavailable', 'attribution' => null];
        }
        return ['state' => 'valid', 'attribution' => $this->attribution($affiliate, 'link', $attributedAt)];
    }

    private function storePending(array $attribution): void
    {
        $payload = ['c' => $attribution['codigo'], 's' => 'link', 'a' => $attribution['atribuido_em_timestamp']];
        $token = $this->encode($payload);
        if ($this->emitCookieHeader && !headers_sent()) {
            setcookie(self::COOKIE_NAME, $token, [
                'expires' => $this->now + self::TTL_SECONDS,
                'path' => '/',
                'secure' => $this->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    private function attribution(array $affiliate, string $origin, int $timestamp): array
    {
        return [
            'afiliado_id' => (int) $affiliate['id'],
            'codigo' => (string) $affiliate['codigo'],
            'origem' => $origin,
            'atribuido_em' => date(DATE_ATOM, $timestamp),
            'atribuido_em_timestamp' => $timestamp,
        ];
    }

    private function encode(array $payload): string
    {
        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret, true));
        return $encoded . '.' . $signature;
    }

    private function decode(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }
}
