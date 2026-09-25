<?php

declare(strict_types=1);

namespace App\Api;

use DateTimeImmutable;

final class PublicQuery
{
    public static function listing(array $query): array
    {
        self::keys($query, ['cidade', 'regiao', 'tipo_imovel', 'valor_min', 'valor_max', 'data_inicio', 'data_fim', 'ordenacao', 'page', 'per_page']);
        $filters = [
            'cidade' => self::text($query, 'cidade'),
            'regiao' => self::text($query, 'regiao'),
            'tipo_imovel' => self::choice($query, 'tipo_imovel', ['', 'chacara', 'sitio', 'area_lazer'], ''),
            'ordenacao' => self::choice($query, 'ordenacao', ['relevancia', 'menor_preco', 'maior_preco', 'nome', 'recentes'], 'relevancia'),
            'valor_min' => self::price($query, 'valor_min'),
            'valor_max' => self::price($query, 'valor_max'),
            'data_inicio' => '',
            'data_fim' => '',
        ];
        if ($filters['valor_min'] !== null && $filters['valor_max'] !== null
            && self::cents($filters['valor_min']) > self::cents($filters['valor_max'])) {
            self::invalid('valor_max', 'Deve ser maior ou igual ao valor minimo.');
        }
        if (array_key_exists('data_inicio', $query) || array_key_exists('data_fim', $query)) {
            [$filters['data_inicio'], $filters['data_fim']] = self::period($query);
        }
        return [
            'filters' => $filters,
            'page' => self::integer($query, 'page', 1, 100000, 1),
            'per_page' => self::integer($query, 'per_page', 1, 50, 12),
        ];
    }

    public static function availability(array $query): array
    {
        self::keys($query, ['data_inicio', 'data_fim']);
        return self::period($query);
    }

    public static function keys(array $query, array $allowed): void
    {
        foreach ($query as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                // Do not reflect arbitrary input names or values in the error.
                throw new ApiException(422, 'VALIDATION_ERROR', 'Parametros de consulta nao reconhecidos.');
            }
            if (!is_string($value)) {
                self::invalid($key, 'Deve ser um valor textual unico.');
            }
        }
    }

    public static function id(string $value): int
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int) $value > 2147483647) {
            throw new ApiException(404, 'NOT_FOUND', 'Imovel nao encontrado.');
        }
        return (int) $value;
    }

    public static function cents(string $decimal): int
    {
        if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $decimal, $parts)) {
            throw new \UnexpectedValueException('Invalid public money value.');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private static function price(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) {
            return null;
        }
        $value = trim($query[$key]);
        if (!preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D', $value)) {
            self::invalid($key, 'Use reais decimais entre 0 e 9999999.99, com ponto e ate duas casas.');
        }
        return $value;
    }

    private static function period(array $query): array
    {
        $dates = [];
        foreach (['data_inicio', 'data_fim'] as $key) {
            $value = $query[$key] ?? '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                self::invalid($key, 'Informe uma data valida no formato YYYY-MM-DD.');
            }
            $dates[] = $date;
        }
        $days = (int) $dates[0]->diff($dates[1])->format('%r%a');
        if ($days < 1 || $days > 366) {
            self::invalid('data_fim', 'O periodo deve ter entre 1 e 366 dias, com saida posterior a entrada.');
        }
        return [$dates[0]->format('Y-m-d'), $dates[1]->format('Y-m-d')];
    }

    private static function text(array $query, string $key): string
    {
        $text = trim($query[$key] ?? '');
        if (!mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 100 || preg_match('/[\x00-\x1f\x7f]/', $text)) {
            self::invalid($key, 'Use texto UTF-8 com ate 100 caracteres.');
        }
        return $text;
    }

    private static function choice(array $query, string $key, array $choices, string $default): string
    {
        $value = $query[$key] ?? $default;
        if (!in_array($value, $choices, true)) {
            self::invalid($key, 'Opcao invalida.');
        }
        return $value;
    }

    private static function integer(array $query, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }
        $value = $query[$key];
        if (!preg_match('/^[1-9][0-9]{0,5}$/D', $value) || (int) $value < $min || (int) $value > $max) {
            self::invalid($key, "Use um inteiro entre $min e $max.");
        }
        return (int) $value;
    }

    private static function invalid(string $key, string $message): never
    {
        throw new ApiException(422, 'VALIDATION_ERROR', 'Parametros invalidos.', [$key => $message]);
    }
}
