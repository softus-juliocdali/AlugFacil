<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;

/** Read-only checkout: opening a page never issues a charge. */
final class ReservationPaymentPresentation
{
    public function __construct(private ?PDO $db = null, private ?object $client = null)
    {
        $this->db ??= Database::getConnection();
    }

    public function get(int $reservation, int $user, int $number = 0): ?array
    {
        (new ReservationAuthorization($this->db))->assertReservation($user, $reservation, 'reserva.ver_proprias');
        $q = $this->db->prepare('SELECT o.*, r.status_reserva, r.expira_em, c.limite_ultima_parcela FROM obrigacoes_reserva o JOIN reservas r ON r.id=o.reserva_id LEFT JOIN cronogramas_reserva c ON c.reserva_id=r.id WHERE o.reserva_id=:r AND o.numero=:n');
        $q->execute(['r'=>$reservation, 'n'=>$number]);
        $o = $q->fetch();
        if (!$o || empty($o['asaas_payment_id'])) return null;
        $result = ['numero'=>$number, 'valor_centavos'=>(int)$o['total_centavos'], 'vencimento'=>$o['vencimento_em'], 'estado'=>$o['estado'], 'metodo'=>$o['forma_pagamento']];
        if (!in_array($o['status_reserva'], ['aguardando_pagamento','confirmada','em_andamento'], true) || $o['estado'] !== 'pendente') return $result;
        $deadline = $number === 0 ? $o['expira_em'] : $o['cancelamento_em'];
        if ($number > 0 && !empty($o['limite_ultima_parcela']) && (!$deadline || strtotime($o['limite_ultima_parcela']) < strtotime($deadline))) $deadline = $o['limite_ultima_parcela'];
        if ($deadline && strtotime($deadline) <= time()) return $result + ['aviso'=>'Prazo de pagamento encerrado.'];
        try {
            $client = $this->client ??= new AsaasHttpClient();
            $payment = $client->consultarCobranca($o['asaas_payment_id']);
            if (($payment['id'] ?? '') !== $o['asaas_payment_id']) throw new \RuntimeException('Cobranca divergente.');
            $result['estado'] = $payment['status'] ?? $o['estado'];
            $result['vencimento'] = $payment['dueDate'] ?? $o['vencimento_em'];
            if (!in_array($result['estado'], ['PENDING','OVERDUE'], true)) return $result;
            if ($o['forma_pagamento'] === 'PIX') {
                $pix = $client->consultarQrCodePix($o['asaas_payment_id']);
                $result['qr'] = $pix['encodedImage'] ?? '';
                $result['codigo'] = $pix['payload'] ?? '';
                $result['expiracao_pix'] = $pix['expirationDate'] ?? null;
            } elseif ($o['forma_pagamento'] === 'BOLETO') {
                $boleto = $client->consultarLinhaDigitavel($o['asaas_payment_id']);
                $result['codigo'] = $boleto['identificationField'] ?? '';
                $result['codigo_barras'] = $boleto['barCode'] ?? '';
                $result['nosso_numero'] = $boleto['nossoNumero'] ?? '';
                $result['documento'] = self::asaasUrl($payment['bankSlipUrl'] ?? '');
            } elseif ($o['forma_pagamento'] === 'CREDIT_CARD') {
                // Preserve the existing hosted card capture; card data never enters this application.
                $result['cartao_url'] = self::asaasUrl($payment['invoiceUrl'] ?? '');
            }
        } catch (\Throwable $e) {
            $result['aviso'] = 'Dados de pagamento temporariamente indisponiveis. Atualize esta pagina; a cobranca existente sera reutilizada.';
        }
        return $result;
    }

    private static function asaasUrl(string $url): ?string
    {
        return preg_match('~^https://([a-z0-9-]+\.)*asaas\.com/[^\s]*$~iD', $url) ? $url : null;
    }
}
