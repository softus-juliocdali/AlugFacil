<?php
declare(strict_types=1);namespace App\Services;
final class AsaasApiException extends \RuntimeException
{
 public function __construct(string $message,int $http,public readonly string $gatewayCode='unspecified'){parent::__construct($message,$http);}
}
