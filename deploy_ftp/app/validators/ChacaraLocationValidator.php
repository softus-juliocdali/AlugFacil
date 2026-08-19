<?php

declare(strict_types=1);

namespace App\Validators;

use InvalidArgumentException;

final class ChacaraLocationValidator
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public static function normalizarEstado(string $estado): ?string
    {
        $estado = mb_strtoupper(trim($estado));

        if ($estado === '') {
            return null;
        }

        if (mb_strlen($estado) !== 2 || !in_array($estado, self::UFS, true)) {
            throw new InvalidArgumentException('Informe um estado brasileiro valido pela sigla de duas letras.');
        }

        return $estado;
    }

    public static function normalizarCoordenada(
        string $valor,
        float $minimo,
        float $maximo,
        string $campo
    ): ?string {
        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        if (preg_match('/^[+-]?(?:\d+(?:[.,]\d*)?|[.,]\d+)$/', $valor) !== 1) {
            throw new InvalidArgumentException('Informe uma ' . $campo . ' valida.');
        }

        $numero = (float) str_replace(',', '.', $valor);
        if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
            throw new InvalidArgumentException('Informe uma ' . $campo . ' valida.');
        }

        if ($numero === 0.0) {
            $numero = 0.0;
        }

        return rtrim(rtrim(number_format($numero, 7, '.', ''), '0'), '.');
    }
}
