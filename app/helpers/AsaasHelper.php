<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Database;
use RuntimeException;
use Throwable;
use App\Services\ReservaStatusService;
use App\Models\AsaasWebhookEvento;
use App\Services\AsaasHttpClient;
use App\Services\AsaasPaymentClientInterface;
use App\Services\AsaasEnvironment;

final class AsaasHelper
{
    private AsaasPaymentClientInterface $client;
    public function __construct(?AsaasPaymentClientInterface $client = null)
    {
        $this->client = $client ?? new AsaasHttpClient();
    }

    public function criarClienteAsaas(array $dadosUsuario): array
    {
        AsaasEnvironment::assertCredentials();
        $uid=(int)($dadosUsuario['id']??0);
        if($uid<1)throw new RuntimeException('Identidade do pagador obrigatoria.');
        return (new \App\Services\AsaasCustomerService($this->client))->obter($uid);
    }

    public function criarCobranca(array $dadosReserva): array
    {
        AsaasEnvironment::assertCredentials(true);

        $cliente = $this->criarClienteAsaas($dadosReserva['cliente'] ?? []);
        $centavos=(int)($dadosReserva['valor_total_centavos']??0);
        $valor=\App\Services\PrecificacaoReservaService::centavosParaDecimal($centavos);

        if ($valor <= 0) {
            throw new RuntimeException('Valor da cobranca invalido.');
        }

        $vencimento = (string) ($dadosReserva['data_vencimento'] ?? date('Y-m-d', strtotime('+1 day')));

        $externalReference='reserva_' . (int) ($dadosReserva['id'] ?? 0);
        $existentes=$this->client->listarCobrancas(['externalReference'=>$externalReference]);
        if(isset($existentes['data'][0])&&is_array($existentes['data'][0]))return $existentes['data'][0];
        $payload=[
            'customer' => $cliente['id'] ?? '',
            'billingType' => 'PIX',
            'value' => $valor,
            'dueDate' => $vencimento,
            'description' => $this->descricaoCobranca($dadosReserva),
            'externalReference' => $externalReference,
        ];
        if(isset($dadosReserva['billing_type'])&&strtoupper((string)$dadosReserva['billing_type'])!=='PIX')throw new RuntimeException('Reservas aceitam exclusivamente PIX.');
        return $this->client->criarCobranca($payload);
    }

    public function consultarCobranca(string $idCobranca): array
    {
        $this->garantirConfigurado();

        return $this->client->consultarCobranca($idCobranca);
    }

    public function consultarQrCodePix(string $idCobranca): array
    {
        return $this->client->consultarQrCodePix($idCobranca);
    }

    public function atualizarStatusPagamento(string $idCobranca): ?array
    {
        $cobranca = $this->consultarCobranca($idCobranca);
        $payload = [
            'id' => 'reconcile_' . hash('sha256', $idCobranca . '|' . (string) ($cobranca['status'] ?? '')),
            'event' => 'PAYMENT_' . (string) ($cobranca['status'] ?? 'UPDATED'),
            'payment' => $cobranca,
        ];

        return $this->tratarWebhook($payload);
    }

    public function tratarWebhook(array $payload): ?array
    {
        if (trim((string)($payload['id']??''))==='' || trim((string)($payload['event']??''))==='') throw new RuntimeException('Evento Asaas sem identificadores obrigatorios.');
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return (new AsaasWebhookEvento())->receber($payload,$json);
    }

    public function configurado(): bool
    {
        try { AsaasEnvironment::assertCredentials(); return true; } catch (Throwable) { return false; }
    }

    public static function logErro(string $mensagem, array $contexto = []): void
    {
        $event = isset($contexto['event']) ? ' Evento: ' . (string) $contexto['event'] . '.' : '';
        app_log($mensagem . $event);
    }

    private function localizarCliente(string $externalReference): ?array
    {
        $resposta = $this->client->listarClientes(['externalReference'=>$externalReference]);
        $clientes = $resposta['data'] ?? [];

        return is_array($clientes) && isset($clientes[0]) && is_array($clientes[0]) ? $clientes[0] : null;
    }

    private function garantirConfigurado(): void
    {
        if (!$this->configurado()) {
            throw new RuntimeException('Integração Asaas não configurada. Informe ASAAS_API_KEY para gerar o pagamento.');
        }
    }

    private function referenciaCliente(array $dadosUsuario): string
    {
        $id = (int) ($dadosUsuario['id'] ?? 0);

        if ($id > 0) {
            return 'usuario_' . $id;
        }

        return 'email_' . sha1((string) ($dadosUsuario['email'] ?? 'cliente'));
    }

    private function descricaoCobranca(array $dadosReserva): string
    {
        $id = (int) ($dadosReserva['id'] ?? 0);
        $nomeChacara = trim((string) ($dadosReserva['chacara_nome'] ?? 'chacara'));
        $inicio = (string) ($dadosReserva['data_inicio'] ?? '');
        $fim = (string) ($dadosReserva['data_fim'] ?? '');

        return sprintf('Reserva #%d - %s (%s a %s)', $id, $nomeChacara, $inicio, $fim);
    }

    private function registrarPagamento(array $reserva, array $pagamento, string $statusPagamento): void
    {
        $db = Database::getConnection();
        $idTransacao = (string) ($pagamento['id'] ?? '');

        if ($idTransacao === '') {
            return;
        }

        $dataPagamento = $statusPagamento === 'pago'
            ? (string) ($pagamento['paymentDate'] ?? $pagamento['confirmedDate'] ?? date('Y-m-d H:i:s'))
            : null;

        $db->prepare(
            <<<'SQL'
                INSERT INTO pagamentos
                    (reserva_id, usuario_id, valor, forma_pagamento, status_pagamento, id_transacao_asaas, data_pagamento)
                VALUES
                    (:reserva_id, :usuario_id, :valor, :forma_pagamento, :status_pagamento, :id_transacao_asaas, :data_pagamento)
                ON CONFLICT (id_transacao_asaas)
                DO UPDATE SET
                    status_pagamento = CASE WHEN pagamentos.status_pagamento = 'pago' THEN 'pago' ELSE EXCLUDED.status_pagamento END,
                    forma_pagamento = EXCLUDED.forma_pagamento,
                    data_pagamento = COALESCE(EXCLUDED.data_pagamento, pagamentos.data_pagamento)
                SQL
        )->execute([
            'reserva_id' => (int) $reserva['id'],
            'usuario_id' => (int) $reserva['usuario_id'],
            'valor' => number_format((float) ($pagamento['value'] ?? $reserva['valor_total']), 2, '.', ''),
            'forma_pagamento' => $this->mapearFormaPagamento((string) ($pagamento['billingType'] ?? '')),
            'status_pagamento' => $statusPagamento,
            'id_transacao_asaas' => $idTransacao,
            'data_pagamento' => $dataPagamento,
        ]);
    }

    private function mapearStatus(string $evento, string $statusAsaas): array
    {
        $chave = $evento !== '' ? $evento : 'PAYMENT_' . $statusAsaas;

        return match ($chave) {
            'PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED' => [
                'pagamento' => 'pago',
                'reserva' => 'confirmada',
                'reconhecido' => true,
            ],
            'PAYMENT_DELETED' => [
                'pagamento' => 'cancelado',
                'reserva' => 'cancelada',
                'reconhecido' => true,
            ],
            'PAYMENT_REFUNDED' => [
                'pagamento' => 'estornado',
                'reserva' => 'cancelada',
                'reconhecido' => true,
            ],
            'PAYMENT_CREATED', 'PAYMENT_OVERDUE', 'PAYMENT_PENDING' => [
                'pagamento' => 'pendente',
                'reserva' => $chave === 'PAYMENT_CREATED' || $chave === 'PAYMENT_OVERDUE'
                    ? 'aguardando_pagamento'
                    : null,
                'reconhecido' => true,
            ],
            default => ['pagamento' => null, 'reserva' => null, 'reconhecido' => false],
        };
    }

    private function mapearFormaPagamento(string $billingType): string
    {
        return match (strtoupper($billingType)) {
            'PIX' => 'pix',
            'CREDIT_CARD' => 'cartao_credito',
            'BOLETO' => 'boleto',
            default => 'transferencia',
        };
    }

    private function somenteDigitos(string $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', $valor);

        return $digitos !== '' ? $digitos : null;
    }

    private function registrarLog(string $mensagem, array $contexto = []): void
    {
        self::logErro($mensagem, $contexto);
    }
}

function criarClienteAsaas(array $dadosUsuario): array
{
    return (new AsaasHelper())->criarClienteAsaas($dadosUsuario);
}

function criarCobranca(array $dadosReserva): array
{
    return (new AsaasHelper())->criarCobranca($dadosReserva);
}

function consultarCobranca(string $idCobranca): array
{
    return (new AsaasHelper())->consultarCobranca($idCobranca);
}

function atualizarStatusPagamento(string $idCobranca): ?array
{
    return (new AsaasHelper())->atualizarStatusPagamento($idCobranca);
}

function tratarWebhook(array $payload): ?array
{
    return (new AsaasHelper())->tratarWebhook($payload);
}
