<?php

declare(strict_types=1);

namespace App\Validators;

use InvalidArgumentException;

final class ChacaraHoursValidator
{
    public const DEFAULTS = [
        'checkin_hora_inicial' => '14:00',
        'checkin_hora_final' => '18:00',
        'checkout_hora_inicial' => '08:00',
        'checkout_hora_final' => '11:00',
    ];

    public static function normalizar(array $dados): array
    {
        $horarios = [];
        foreach (self::DEFAULTS as $campo => $padrao) {
            $valor = trim((string) ($dados[$campo] ?? ''));
            $horarios[$campo] = $valor === '' ? $padrao : $valor;
        }

        return $horarios;
    }

    public static function validar(array $dados): void
    {
        foreach (array_keys(self::DEFAULTS) as $campo) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) ($dados[$campo] ?? ''))) {
                throw new InvalidArgumentException('Informe todos os horarios da hospedagem.');
            }
        }

        if ($dados['checkin_hora_inicial'] >= $dados['checkin_hora_final']
            || $dados['checkout_hora_inicial'] >= $dados['checkout_hora_final']
        ) {
            throw new InvalidArgumentException('O horario inicial deve ser anterior ao horario final.');
        }
    }
}
