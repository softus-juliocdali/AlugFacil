<?php
declare(strict_types=1);
namespace App\Services;
final class FinancialPayloadFilter
{
 public static function sanitize(array $data):array
 {foreach($data as $key=>$value){if(preg_match('/^(apiKey|access_token|authorization|proxy-authorization|password|senha|creditCard|creditCardHolderInfo|creditCardToken|creditCardNumber|cardNumber|ccv|cvv|securityCode|remoteIp)$/i',(string)$key)){unset($data[$key]);continue;}if(is_array($value))$data[$key]=self::sanitize($value);}return $data;}
}
