<?php

declare(strict_types=1);

final class MigrationExecutionGuard
{
    private const DEVELOPMENT_DATABASE = 'alugfacil_dev';
    private const PRODUCTION_CONFIRMATION = '--confirm-production';

    /**
     * @param list<string> $arguments
     * @return array{environment: string, database: string, migration: string}
     */
    public static function authorize(PDO $db, array $arguments, string $migration): array
    {
        $environment = trim((string) (getenv('APP_ENV') ?: ''));
        $configuredDatabase = trim((string) (getenv('DB_NAME') ?: ''));
        $database = trim((string) $db->query('SELECT current_database()')->fetchColumn());

        self::printTarget($environment, $database, $migration);
        self::assertAllowed($environment, $database, $configuredDatabase, $arguments);

        return [
            'environment' => strtolower($environment),
            'database' => $database,
            'migration' => $migration,
        ];
    }

    /**
     * @param list<string> $arguments
     */
    public static function assertAllowed(
        string $environment,
        string $database,
        string $configuredDatabase,
        array $arguments
    ): void {
        $environment = strtolower(trim($environment));
        $database = trim($database);
        $configuredDatabase = trim($configuredDatabase);

        if ($environment === '') {
            throw new RuntimeException('APP_ENV nao informado; destino desconhecido.');
        }

        if ($database === '' || $configuredDatabase === '') {
            throw new RuntimeException('Banco vazio ou desconhecido.');
        }

        if (!hash_equals($configuredDatabase, $database)) {
            throw new RuntimeException('Banco real difere do DB_NAME configurado.');
        }

        if ($environment === 'development') {
            if ($database !== self::DEVELOPMENT_DATABASE) {
                throw new RuntimeException('Desenvolvimento permitido somente em alugfacil_dev.');
            }

            return;
        }

        if ($environment !== 'production') {
            throw new RuntimeException('APP_ENV desconhecido; use development ou production.');
        }

        if ($database === self::DEVELOPMENT_DATABASE) {
            throw new RuntimeException('alugfacil_dev e recusado explicitamente em producao.');
        }

        if (!in_array(self::PRODUCTION_CONFIRMATION, $arguments, true)) {
            throw new RuntimeException(
                'Producao exige confirmacao explicita via --confirm-production.'
            );
        }
    }

    private static function printTarget(string $environment, string $database, string $migration): void
    {
        echo 'Ambiente: ' . ($environment !== '' ? $environment : '[nao informado]') . PHP_EOL;
        echo 'Banco de destino: ' . ($database !== '' ? $database : '[nao identificado]') . PHP_EOL;
        echo 'Migration: ' . $migration . PHP_EOL;
    }
}
