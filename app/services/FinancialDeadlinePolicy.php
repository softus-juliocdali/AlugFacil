<?php
declare(strict_types=1);
namespace App\Services;
use DateTimeImmutable;
final class FinancialDeadlinePolicy
{
 public static function mayCancel(DateTimeImmutable $now,DateTimeImmutable $deadline):bool{return $now<$deadline;}
 public static function mayTransfer(DateTimeImmutable $now,DateTimeImmutable $deadline):bool{return $now>=$deadline;}
}
