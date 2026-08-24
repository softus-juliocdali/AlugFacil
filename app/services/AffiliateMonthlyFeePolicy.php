<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AffiliateMonthlyFeePolicy
{
    public const REQUIRED_MESSAGE = 'Chácaras de proprietários vinculados a afiliados devem obrigatoriamente possuir mensalidade.';

    public static function ownerRequiresMonthlyFee(array $ownerOrProperty): bool
    {
        $affiliateId = $ownerOrProperty['afiliado_id'] ?? null;
        return $affiliateId !== null && (int) $affiliateId > 0;
    }

    public static function propertyRequiresMonthlyFee(array $property): bool
    {
        return self::ownerRequiresMonthlyFee($property);
    }

    public static function assertModeAllowed(array $property, string $mode): void
    {
        if ($mode === 'sem' && self::propertyRequiresMonthlyFee($property)) {
            throw new RuntimeException(self::REQUIRED_MESSAGE);
        }
    }

    public static function assertConfigurationAllowed(array $property, bool $active): void
    {
        if (!$active && self::propertyRequiresMonthlyFee($property)) {
            throw new RuntimeException(self::REQUIRED_MESSAGE);
        }
    }
}
