<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use RuntimeException;
final class AsaasSubaccountVault
{
    private static function key():string
    {
        $key=(string)getenv('ASAAS_SUBACCOUNT_ENCRYPTION_KEY');
        if(!extension_loaded('openssl')||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Cofre de subcontas nao configurado; criacao bloqueada antes do POST.');
        return hex2bin($key);
    }
    public static function ready():void{self::key();}
    public static function save(string $scope,string $account,string $secret):void
    {
        $key=self::key();$nonce=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($secret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'sandbox|'.$scope.'|'.$account,16);
        if($cipher===false)throw new RuntimeException('Falha ao cifrar credencial.');
        $encrypted=base64_encode($nonce.$tag.$cipher);
        Database::getConnection()->prepare("INSERT INTO asaas_subconta_credenciais(conta_gateway,asaas_account_id,ambiente,segredo_cifrado) VALUES(:c,:a,'sandbox',:s) ON CONFLICT(ambiente,conta_gateway,asaas_account_id) DO UPDATE SET segredo_cifrado=EXCLUDED.segredo_cifrado")->execute(['c'=>$scope,'a'=>$account,'s'=>$encrypted]);
        unset($key);
    }
    public static function load(string $scope,string $account):string
    {
        $s=Database::getConnection()->prepare("SELECT segredo_cifrado FROM asaas_subconta_credenciais WHERE ambiente='sandbox' AND conta_gateway=:c AND asaas_account_id=:a");$s->execute(['c'=>$scope,'a'=>$account]);$cipher=$s->fetchColumn();
        if(!$cipher)throw new RuntimeException('Credencial da subconta indisponivel; revisar provisionamento.');
        $bytes=base64_decode($cipher,true);if($bytes===false)throw new RuntimeException('Credencial cifrada invalida.');$key=self::key();
        if(strlen($bytes)<29)throw new RuntimeException('Credencial cifrada incompleta.');
        $secret=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),'sandbox|'.$scope.'|'.$account);unset($key);
        if($secret===false)throw new RuntimeException('Nao foi possivel abrir o cofre da subconta.');return $secret;
    }
}
