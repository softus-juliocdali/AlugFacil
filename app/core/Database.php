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

        if ($config['DB_DRIVER'] !== 'pgsql') {
            throw new RuntimeException('O driver de banco configurado deve ser pgsql.');
        }

        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('A extensão PDO PostgreSQL (pdo_pgsql) não está habilitada.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $config['DB_NAME']
        );

        try {
            self::$connection = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            self::$connection->prepare("SET client_encoding TO 'UTF8'")->execute();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Não foi possível conectar ao PostgreSQL. Verifique as variáveis de banco.',
                0,
                $exception
            );
        }

        return self::$connection;
    }

    /**
     * Mantém compatibilidade com chamadas existentes.
     */
    public static function connection(): PDO
    {
        return self::getConnection();
    }
}
