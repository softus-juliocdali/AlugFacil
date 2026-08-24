<?php

declare(strict_types=1);

namespace App\Validators;

final class AffiliateValidator
{
    public const PIX_TYPES = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];

    public static function normalizeDocument(string $document): string
    {
        return preg_replace('/\D+/', '', $document) ?? '';
    }

    public static function documentIsValid(string $document): bool
    {
        $digits = self::normalizeDocument($document);
        return strlen($digits) === 11 ? self::cpfIsValid($digits) : self::cnpjIsValid($digits);
    }

    public static function commissionToBasisPoints(string $value): ?int
    {
        $normalized = trim(str_replace(['%', ' '], '', $value));
        if (!preg_match('/^(\d{1,3})(?:[,.](\d{1,2}))?$/', $normalized, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;
        $basisPoints = ($whole * 100) + $fraction;

        return $basisPoints >= 1 && $basisPoints <= 10000 ? $basisPoints : null;
    }

    public static function pixKeyIsValid(string $type, string $key): bool
    {
        $key = trim($key);
        return match ($type) {
            'cpf' => strlen(self::normalizeDocument($key)) === 11 && self::documentIsValid($key),
            'cnpj' => strlen(self::normalizeDocument($key)) === 14 && self::documentIsValid($key),
            'email' => filter_var($key, FILTER_VALIDATE_EMAIL) !== false,
            'telefone' => ($length = strlen(self::normalizeDocument($key))) >= 10 && $length <= 13,
            'aleatoria' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) === 1,
            default => false,
        };
    }

    private static function cpfIsValid(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($digit = 9; $digit < 11; $digit++) {
            $sum = 0;
            for ($i = 0; $i < $digit; $i++) {
                $sum += (int) $cpf[$i] * (($digit + 1) - $i);
            }
            $check = (10 * $sum) % 11;
            if ($check === 10) {
                $check = 0;
            }
            if ((int) $cpf[$digit] !== $check) {
                return false;
            }
        }
        return true;
    }

    private static function cnpjIsValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]] as $position => $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $cnpj[$index] * $weight;
            }
            $remainder = $sum % 11;
            $check = $remainder < 2 ? 0 : 11 - $remainder;
            if ((int) $cnpj[12 + $position] !== $check) {
                return false;
            }
        }
        return true;
    }
}
