<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class MonthlyCardPayment
{
    public function __construct(private ?PDO $db=null, private ?AsaasPaymentClientInterface $client=null)
    { $this->db ??= Database::getConnection(); }

    public function pay(int $id,int $owner,#[\SensitiveParameter] array $input,string $ip): array
    {
        $q=$this->db->prepare('SELECT * FROM obrigacoes_mensalidades WHERE id=:id AND proprietario_id=:p');
        $q->execute(['id'=>$id,'p'=>$owner]);$o=$q->fetch();
        if (!$o) throw new \DomainException('Obrigação não encontrada.');
        if ($o['estado']==='paga') return ['status'=>'CONFIRMED'];
        if ($o['estado']!=='pendente' || ($o['forma_pagamento']!==null && $o['forma_pagamento']!=='CREDIT_CARD')) throw new RuntimeException('Esta obrigação não está disponível para cartão. Atualize o pagamento existente.');
        if ($this->client===null || $this->client instanceof AsaasHttpClient) FinancialReleasePolicy::assertMonthlyPaymentsAllowed();
        $client=$this->client ??=new AsaasHttpClient();
        $card=self::payload($input,$ip);
        try {
            $o=(new MonthlyBillingService($this->db,$client))->issue($id,$owner,'CREDIT_CARD');
            $pid=$o['asaas_payment_id'];
            $q=$this->db->prepare('SELECT payload,referencia FROM operacoes_financeiras WHERE id=(SELECT operacao_id FROM obrigacoes_mensalidades WHERE id=:id)');$q->execute(['id'=>$id]);$operation=$q->fetch();
            if(!$operation)throw new RuntimeException('Cobrança sem intenção vinculada. Consulte o suporte para conciliar antes de pagar.');
            $expected=json_decode($operation['payload'],true,512,JSON_THROW_ON_ERROR);
            $check=function(#[\SensitiveParameter] array $charge)use($pid,$o,$operation,$expected):array {
                if (($charge['id']??'')!==$pid || ($charge['billingType']??'')!=='CREDIT_CARD' || ($charge['customer']??'')!==$expected['customer'] || ($charge['externalReference']??'')!==$operation['referencia'] || PrecificacaoReservaService::decimalParaCentavos((string)($charge['value']??''))!==(int)$o['valor_centavos']) throw new RuntimeException('Cobrança divergente. Consulte o suporte.');
                return $charge;
            };
            // Persist only the charge identity and amount. Never PAN, CVV, holder data or token.
            return (new FinancialOperationService($client->accountScope(),$this->db))->executar('checkout','mensalidade-cartao:'.$id,1,
                ['payment_id'=>$pid,'value_centavos'=>(int)$o['valor_centavos']],
                function()use($client,$pid,$check):array {
                    $charge=$check($client->consultarCobranca($pid));
                    if(in_array($charge['status']??'',['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true))return [$charge];
                    if(!in_array($charge['status']??'',['PENDING','OVERDUE'],true))throw new RuntimeException('Pagamento em processamento ou encerrado. Atualize o estado antes de continuar.');
                    return [];
                },
                function()use($client,$pid,$card,$check):array {return $check($client->pagarCobrancaCartao($pid,$card));});
        } finally { unset($card,$input); }
    }

    public static function payload(#[\SensitiveParameter] array $input,string $ip): array
    {
        $fields=['holderName','number','expiryMonth','expiryYear','ccv','name','email','cpfCnpj','postalCode','addressNumber','phone'];
        foreach($fields as $key)if(!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key])==='' || strlen($input[$key])>180)throw new RuntimeException('Preencha os dados do cartão e do titular.');
        foreach(['number','cpfCnpj','postalCode','phone'] as $key)$input[$key]=preg_replace('/[ .()\-]/','',$input[$key]);
        if(!preg_match('/^[0-9]{13,19}$/D',$input['number']) || !preg_match('/^[0-9]{3,4}$/D',$input['ccv']) || !preg_match('/^(0[1-9]|1[0-2])$/D',$input['expiryMonth']) || !preg_match('/^[0-9]{4}$/D',$input['expiryYear']) || $input['expiryYear'].$input['expiryMonth']<date('Ym') || !preg_match('/^([0-9]{11}|[0-9]{14})$/D',$input['cpfCnpj']) || !preg_match('/^[0-9]{8}$/D',$input['postalCode']) || !preg_match('/^[0-9]{10,11}$/D',$input['phone']) || !filter_var($input['email'],FILTER_VALIDATE_EMAIL) || !filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException('Confira os dados do cartão e do titular.');
        return ['creditCard'=>array_intersect_key($input,array_flip(array_slice($fields,0,5))), 'creditCardHolderInfo'=>array_intersect_key($input,array_flip(array_slice($fields,5))), 'remoteIp'=>$ip];
    }
}
