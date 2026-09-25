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
        if (isset($r['monthly_card_tests']) && !is_array($r['monthly_card_tests'])) throw new \RuntimeException('Registro mensal de testes invalido.');
        return $r;
    }

    public static function assertTestReservation(int $user,int $property): void
    {
        $r=self::registry();
        if (!in_array($user,$r['users'],true)||!in_array($property,$r['properties'],true)) throw new \RuntimeException('Reservas Sandbox liberadas somente para perfis e imoveis de teste autorizados.');
    }

    public static function assertMonthlyPaymentsAllowed(int $obligation,int $owner,string $method): void
    {
        $registry=self::registry();
        if($method==='CREDIT_CARD' && self::monthlyCardTest($registry,$obligation,$owner)!==null)return;
        throw new \RuntimeException('Mensalidade não autorizada para homologação neste ambiente. É necessária uma obrigação sintética de cartão explicitamente autorizada no registro Sandbox. Nenhuma cobrança mensal foi enviada.');
    }

    /** Explicit, expiring permission for ONE synthetic obligation; never a global card switch. */
    private static function monthlyCardTest(array $registry,int $obligation,?int $owner=null): ?array
    {
        foreach($registry['monthly_card_tests']??[] as $test){
            if(!is_array($test) || ($test['synthetic']??false)!==true || ($test['obligation_id']??null)!==$obligation || !is_int($test['expires_at']??null) || $test['expires_at']<time() || $test['expires_at']>$registry['expires_at'])continue;
            $q=\App\Core\Database::getConnection()->prepare("SELECT o.*,p.usuario_id,u.status usuario_status,p.status proprietario_status,c.status_aprovacao,c.status_operacional FROM obrigacoes_mensalidades o JOIN proprietarios p ON p.id=o.proprietario_id JOIN usuarios u ON u.id=p.usuario_id JOIN chacaras c ON c.id=o.chacara_id AND c.proprietario_id=p.id WHERE o.id=:id");
            $q->execute(['id'=>$obligation]);$row=$q->fetch();
            if(!$row || ($owner!==null && (int)$row['proprietario_id']!==$owner) || $row['estado']!=='pendente' || !in_array($row['forma_pagamento'],[null,'CREDIT_CARD'],true) || $row['usuario_status']!=='ativo' || $row['proprietario_status']!=='ativo' || $row['status_aprovacao']!=='aprovada' || $row['status_operacional']!=='indisponivel')return null;
            foreach(['owner_id'=>'proprietario_id','user_id'=>'usuario_id','property_id'=>'chacara_id','value_centavos'=>'valor_centavos'] as $key=>$column)if(!is_int($test[$key]??null) || $test[$key] !== (int)$row[$column])return null;
            // This test profile must respect the gateway minimum already verified for this account.
            if((int)$row['valor_centavos']<1500)return null;
            return $row;
        }
        return null;
    }

    private static function monthlyCustomerAllowed(array $registry,int $user): bool
    {
        foreach($registry['monthly_card_tests']??[] as $test){
            if(is_array($test) && ($test['user_id']??null)===$user && is_int($test['obligation_id']??null) && self::monthlyCardTest($registry,$test['obligation_id'])!==null)return true;
        }
        return false;
    }

    public static function assertExternalMutationAllowed(string $method='',string $path='',#[\SensitiveParameter] array $payload=[]): void
    {
        $r=self::registry();
        $c=FinancialOperationService::outboundContext();
        if ($c===null) throw new \RuntimeException('Mutacao exige intencao de teste duravel.');
        $entity=$c['entity'];$type=$c['type'];$allowed=false;
        if ($method==='POST'&&$path==='/customers'&&$type==='customer'&&preg_match('/^usuario:(\d+)$/D',$entity,$m)) {
            $allowed=(in_array((int)$m[1],$r['users'],true)||self::monthlyCustomerAllowed($r,(int)$m[1]))&&($payload['notificationDisabled']??false)===true;
        } elseif ($method==='POST'&&$path==='/accounts'&&$type==='subconta'&&preg_match('/^proprietario:(\d+)$/D',$entity,$m)) {
            $allowed=in_array((int)$m[1],$r['owners'],true);
        } elseif (($method==='POST'&&$path==='/payments'&&$type==='cobranca'&&preg_match('/^reserva-obrigacao:(\d+)$/D',$entity,$m)) ||
                  ($method==='DELETE'&&preg_match('~^/payments/[a-zA-Z0-9_]+$~D',$path)&&$type==='cancelar_cobranca'&&preg_match('/^obrigacao:(\d+)$/D',$entity,$m))) {
            $q=\App\Core\Database::getConnection()->prepare('SELECT r.usuario_id,r.chacara_id,r.proprietario_id,o.asaas_payment_id FROM obrigacoes_reserva o JOIN reservas r ON r.id=o.reserva_id WHERE o.id=:id');
            $q->execute(['id'=>(int)$m[1]]);$row=$q->fetch();
            $allowed=$row&&in_array((int)$row['usuario_id'],$r['users'],true)&&in_array((int)$row['proprietario_id'],$r['owners'],true)&&in_array((int)$row['chacara_id'],$r['properties'],true);
            if ($method==='DELETE') $allowed=$allowed&&$path==='/payments/'.$row['asaas_payment_id'];
            else $allowed=$allowed&&in_array($payload['billingType']??'',['PIX','BOLETO'],true)&&!isset($payload['split']);
        } elseif($method==='POST' && (($path==='/payments' && $type==='cobranca' && preg_match('/^mensalidade:(\d+)$/D',$entity,$m)) || ($type==='checkout' && preg_match('/^mensalidade-cartao:(\d+)$/D',$entity,$m)))) {
            $monthly=self::monthlyCardTest($r,(int)$m[1]);
            if($monthly && $type==='checkout')$allowed=!empty($monthly['asaas_payment_id']) && $path==='/payments/'.$monthly['asaas_payment_id'].'/payWithCreditCard';
            elseif($monthly){
                $q=\App\Core\Database::getConnection()->prepare("SELECT 1 FROM asaas_customers WHERE usuario_id=:u AND ambiente='sandbox' AND asaas_customer_id=:c");$q->execute(['u'=>$monthly['usuario_id'],'c'=>$payload['customer']??'']);
                $allowed=(bool)$q->fetchColumn() && ($payload['billingType']??'')==='CREDIT_CARD' && ($payload['dueDate']??'')===$monthly['vencimento'] && PrecificacaoReservaService::decimalParaCentavos((string)($payload['value']??'0'))===(int)$monthly['valor_centavos'] && !isset($payload['split']);
            }
        }
        if (!$allowed) throw new \RuntimeException('Operacao fora do escopo Sandbox de teste; repasses e transferencias bloqueados.');
    }
}
