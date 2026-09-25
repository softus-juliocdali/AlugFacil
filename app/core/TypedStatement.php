<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use PDOStatement;

/** PDO::execute(array) otherwise coerces false to an invalid PostgreSQL boolean ''. */
final class TypedStatement extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if ($params === null) return parent::execute();
        foreach ($params as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $this->bindValue(is_int($key) ? $key + 1 : ':'.ltrim($key, ':'), $value, $type);
        }
        return parent::execute();
    }
}
