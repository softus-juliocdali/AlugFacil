<?php
declare(strict_types=1);
namespace App\Services;
/** Affiliate attribution does not determine whether a property owes a monthly fee. */
final class AffiliateMonthlyFeePolicy
{
 public const REQUIRED_MESSAGE='A mensalidade segue a configuracao global e a isencao do imovel.';
 public static function ownerRequiresMonthlyFee(array $ownerOrProperty):bool{return false;}
 public static function propertyRequiresMonthlyFee(array $property):bool{return false;}
 public static function assertModeAllowed(array $property,string $mode):void{if(!in_array($mode,['sem','com'],true))throw new \RuntimeException('Condicao comercial invalida.');}
 public static function assertConfigurationAllowed(array $property,bool $active):void{}
}
