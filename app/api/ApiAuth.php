<?php
declare(strict_types=1);
namespace App\Api;

/** Never reads cookies or starts a PHP session. */
final class ApiAuth
{
    public static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $matches)) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Entre novamente para continuar.');
        }
        return $matches[1];
    }

    public static function user(): array
    {
        return (new MobileSessions())->authenticate(self::bearer());
    }
}
