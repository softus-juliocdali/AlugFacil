<?php
declare(strict_types=1);
namespace App\Services;

/** Explicit Sandbox test allowlist. Transfers are never enabled by this release. */
final class FinancialReleasePolicy
{
    public static function enabled(): bool
    {
        return getenv('FINANCIAL_RELEASE_MODE') === 'sandbox-test';
    }

    public static function registry(): array
    {
        if (!self::enabled()) throw new \RuntimeException('Release financeiro congelado: mutacoes Asaas indisponiveis ate homologacao.');
        AsaasEnvironment::assertCredentials(true);
        $path=(string)getenv('FINANCIAL_TEST_REGISTRY');
        if ($path==='' || !is_file($path) || is_link($path)) throw new \RuntimeException('Registro privado de testes ausente.');
        if (PHP_OS_FAMILY!=='Windows' && (fileperms($path)&0022)!==0) throw new \RuntimeException('Registro de testes gravavel por terceiros.');
        $r=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (($r['environment']??'')!=='sandbox' || ($r['expires_at']??0)<time()) throw new \RuntimeException('Autorizacao de testes Sandbox vencida.');
        foreach (['users','owners','properties'] as $k) if (!is_array($r[$k]??null)) throw new \RuntimeException('Registro de testes invalido.');
        return $r;
    }

    public static function assertTestReservation(int $user,int $property): void
    {
        $r=self::registry();
        if (!in_array($user,$r['users'],true)||!in_array($property,$r['properties'],true)) throw new \RuntimeException('Reservas Sandbox liberadas somente para perfis e imoveis de teste autorizados.');
    }

    public static function assertExternalMutationAllowed(string $method='',string $path='',array $payload=[]): void
    {
        $r=self::registry();
        $c=FinancialOperationService::outboundContext();
        if ($c===null) throw new \RuntimeException('Mutacao exige intencao de teste duravel.');
        $entity=$c['entity'];$type=$c['type'];$allowed=false;
        if ($method==='POST'&&$path==='/customers'&&$type==='customer'&&preg_match('/^usuario:(\d+)$/D',$entity,$m)) {
            $allowed=in_array((int)$m[1],$r['users'],true)&&($payload['notificationDisabled']??false)===true;
        } elseif ($method==='POST'&&$path==='/accounts'&&$type==='subconta'&&preg_match('/^proprietario:(\d+)$/D',$entity,$m)) {
            $allowed=in_array((int)$m[1],$r['owners'],true);
        } elseif (($method==='POST'&&$path==='/payments'&&$type==='cobranca'&&preg_match('/^reserva-obrigacao:(\d+)$/D',$entity,$m)) ||
                  ($method==='DELETE'&&preg_match('~^/payments/[a-zA-Z0-9_]+$~D',$path)&&$type==='cancelar_cobranca'&&preg_match('/^obrigacao:(\d+)$/D',$entity,$m))) {
            $q=\App\Core\Database::getConnection()->prepare('SELECT r.usuario_id,r.chacara_id,r.proprietario_id,o.asaas_payment_id FROM obrigacoes_reserva o JOIN reservas r ON r.id=o.reserva_id WHERE o.id=:id');
            $q->execute(['id'=>(int)$m[1]]);$row=$q->fetch();
            $allowed=$row&&in_array((int)$row['usuario_id'],$r['users'],true)&&in_array((int)$row['proprietario_id'],$r['owners'],true)&&in_array((int)$row['chacara_id'],$r['properties'],true);
            if ($method==='DELETE') $allowed=$allowed&&$path==='/payments/'.$row['asaas_payment_id'];
            else $allowed=$allowed&&in_array($payload['billingType']??'',['PIX','BOLETO'],true)&&!isset($payload['split']);
        }
        if (!$allowed) throw new \RuntimeException('Operacao fora do escopo Sandbox de teste; repasses e transferencias bloqueados.');
    }
}
