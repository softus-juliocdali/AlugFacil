<?php
declare(strict_types=1);
namespace App\Services;

final class FinancialErrorMessage
{
    public static function publicMessage(\Throwable $error, string $fallback): string
    {
        // Only application validation errors are safe for the public response.
        // PDOException and gateway exceptions also extend RuntimeException.
        return in_array(get_class($error), [\RuntimeException::class, \DomainException::class], true)
            ? $error->getMessage() : $fallback;
    }
}
