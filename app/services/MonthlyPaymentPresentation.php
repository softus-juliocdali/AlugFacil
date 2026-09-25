<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;

/** Opening/reopening only reads the owner's existing obligation and gateway charge. */
final class MonthlyPaymentPresentation
{
    public function __construct(private ?PDO $db=null, private ?object $client=null) { $this->db??=Database::getConnection(); }
    public function get(int $id,int $owner): array
    {
        $q=$this->db->prepare('SELECT id,valor_centavos,vencimento,estado,forma_pagamento,asaas_payment_id FROM obrigacoes_mensalidades WHERE id=:id AND proprietario_id=:p');
        $q->execute(['id'=>$id,'p'=>$owner]);$o=$q->fetch();
        if (!$o) throw new \DomainException('Obrigação não encontrada.');
        $out=['id'=>(int)$o['id'],'valor_centavos'=>(int)$o['valor_centavos'],'vencimento'=>$o['vencimento'],'estado'=>$o['estado'],'metodo'=>$o['forma_pagamento'],'emitida'=>!empty($o['asaas_payment_id'])];
        if (!$out['emitida'] || $o['estado']!=='pendente') return $out;
        try {
            $client=$this->client??=new AsaasHttpClient();$charge=$client->consultarCobranca($o['asaas_payment_id']);
            if (($charge['id']??'')!==$o['asaas_payment_id'] || PrecificacaoReservaService::decimalParaCentavos((string)($charge['value']??''))!==(int)$o['valor_centavos'] || ($charge['billingType']??'')!==$o['forma_pagamento']) throw new \RuntimeException('Charge mismatch');
            $out['estado']=$charge['status'];
            if (in_array($charge['status'],['PENDING','OVERDUE'],true) && $o['forma_pagamento']==='PIX') {
                $pix=$client->consultarQrCodePix($o['asaas_payment_id']);
                $out['qr']=$pix['encodedImage']??'';$out['codigo']=$pix['payload']??'';$out['expiracao']=$pix['expirationDate']??null;
            }
        } catch (\Throwable) { $out['aviso']='Não foi possível consultar o pagamento agora. Atualize para consultar a mesma cobrança.'; }
        return $out;
    }
}
