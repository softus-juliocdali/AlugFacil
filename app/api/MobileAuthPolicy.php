<?php
declare(strict_types=1);
namespace App\Api;
final class MobileAuthPolicy
{
    public const ACCESS_SECONDS = 900;
    public const REFRESH_SECONDS = 2592000;
    public const SESSION_SECONDS = 7776000;
    public const RATE_LIMITS = ['login' => [20, 900], 'cadastro' => [5, 3600], 'refresh' => [60, 60]];
}
