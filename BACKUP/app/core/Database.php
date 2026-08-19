<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $appConfig = require APP_ROOT . '/app/config/config.php';
        $config = $appConfig['database'];
        $required = ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => trim((string) ($config[$key] ?? '')) === ''
        ));

        if ($missing !== []) {
            self::configurationError(
                $appConfig['app_env'],
                'Configuracao obrigatoria ausente: ' . implode(', ', $missing) . '.'
            );
        }
        if ($config['DB_DRIVER'] !== 'pgsql') {
            self::configurationError($appConfig['app_env'], 'O driver de banco configurado deve ser pgsql.');
        }
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::configurationError($appConfig['app_env'], 'A extensao PDO PostgreSQL (pdo_pgsql) nao esta habilitada.');
        }
        if (filter_var($config['DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            self::configurationError($appConfig['app_env'], 'A porta configurada para o PostgreSQL e invalida.');
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $config['DB_CHARSET']) !== 1) {
            self::configurationError($appConfig['app_env'], 'O charset configurado para o PostgreSQL e invalido.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['DB_HOST'], $config['DB_PORT'], $config['DB_NAME']);

        try {
            self::$connection = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$connection->exec('SET client_encoding TO ' . self::$connection->quote($config['DB_CHARSET']));
        } catch (PDOException $exception) {
            app_log('Falha na conexao PostgreSQL: ' . $exception->getMessage());
            $message = $appConfig['app_env'] === 'development'
                ? 'Nao foi possivel conectar ao PostgreSQL: ' . $exception->getMessage()
                : 'Nao foi possivel conectar ao banco de dados.';
            throw new RuntimeException($message, 0);
        }

        return self::$connection;
    }

    public static function connection(): PDO
    {
        return self::getConnection();
    }

    private static function configurationError(string $environment, string $detail): never
    {
        app_log($detail);
        throw new RuntimeException(
            $environment === 'development' ? $detail : 'Nao foi possivel conectar ao banco de dados.'
        );
    }
}
