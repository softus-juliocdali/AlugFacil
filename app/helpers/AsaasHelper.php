<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class AsaasHelper
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $config = require APP_ROOT . '/app/config/apis.php';
        $asaas = $config['asaas'] ?? [];

        $this->apiKey = trim((string) ($asaas['api_key'] ?? ''));
        $this->baseUrl = rtrim((string) ($asaas['base_url'] ?? 'https://sandbox.asaas.com/api/v3'), '/');
    }

    public function criarClienteAsaas(array $dadosUsuario): array
    {
        $this->garantirConfigurado();

        $externalReference = $this->referenciaCliente($dadosUsuario);
        $clienteExistente = $this->localizarCliente($externalReference);

        if ($clienteExistente !== null) {
            return $clienteExistente;
        }

        return $this->request('POST', '/customers', [
            'name' => $dadosUsuario['nome'] ?? 'Cliente Alug Facil',
            'email' => $dadosUsuario['email'] ?? null,
            'phone' => $this->somenteDigitos((string) ($dadosUsuario['telefone'] ?? '')),
            'externalReference' => $externalReference,
            'notificationDisabled' => false,
        ]);
    }

    public function criarCobranca(array $dadosReserva): array
    {
        $this->garantirConfigurado();

        $cliente = $this->criarClienteAsaas($dadosReserva['cliente'] ?? []);
        $valor = round((float) ($dadosReserva['valor_total'] ?? 0), 2);

        if ($valor <= 0) {
            throw new RuntimeException('Valor da cobranca invalido.');
        }

        $vencimento = (string) ($dadosReserva['data_vencimento'] ?? date('Y-m-d', strtotime('+1 day')));

        return $this->request('POST', '/payments', [
            'customer' => $cliente['id'] ?? '',
            'billingType' => $dadosReserva['billing_type'] ?? 'UNDEFINED',
            'value' => $valor,
            'dueDate' => $vencimento,
            'description' => $this->descricaoCobranca($dadosReserva),
            'externalReference' => 'reserva_' . (int) ($dadosReserva['id'] ?? 0),
        ]);
    }

    public function consultarCobranca(string $idCobranca): array
    {
        $this->garantirConfigurado();

        return $this->request('GET', '/payments/' . rawurlencode($idCobranca));
    }

    public function atualizarStatusPagamento(string $idCobranca): ?array
    {
        $cobranca = $this->consultarCobranca($idCobranca);
        $payload = [
            'event' => 'PAYMENT_' . (string) ($cobranca['status'] ?? 'UPDATED'),
            'payment' => $cobranca,
        ];

        return $this->tratarWebhook($payload);
    }

    public function tratarWebhook(array $payload): ?array
    {
        $evento = (string) ($payload['event'] ?? '');
        $pagamento = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $idCobranca = (string) ($pagamento['id'] ?? '');

        if ($idCobranca === '') {
            $this->registrarLog('Webhook sem payment.id.', $payload);
            return null;
        }

        $status = $this->mapearStatus($evento, (string) ($pagamento['status'] ?? ''));
        $statusPagamento = $status['pagamento'];
        $statusReserva = $status['reserva'];

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'SELECT id, usuario_id, valor_total FROM reservas WHERE id_cobranca_asaas = :id_cobranca LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['id_cobranca' => $idCobranca]);
            $reserva = $statement->fetch();

            if (!$reserva) {
                $db->commit();
                $this->registrarLog('Reserva nao encontrada para cobranca Asaas.', [
                    'id_cobranca_asaas' => $idCobranca,
                    'event' => $evento,
                ]);
                return null;
            }

            $updates = ['status_pagamento = :status_pagamento'];
            $params = [
                'id' => (int) $reserva['id'],
                'status_pagamento' => $statusPagamento,
            ];

            if ($statusReserva !== null) {
                $updates[] = 'status_reserva = :status_reserva';
                $params['status_reserva'] = $statusReserva;
            }

            $db->prepare(
                'UPDATE reservas SET ' . implode(', ', $updates) . ' WHERE id = :id'
            )->execute($params);

            $this->registrarPagamento($reserva, $pagamento, $statusPagamento);

            $db->commit();

            return [
                'reserva_id' => (int) $reserva['id'],
                'id_cobranca_asaas' => $idCobranca,
                'evento' => $evento,
                'status_pagamento' => $statusPagamento,
                'status_reserva' => $statusReserva,
            ];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $this->registrarLog('Erro ao tratar webhook Asaas: ' . $exception->getMessage(), $payload);
            throw $exception;
        }
    }

    public function configurado(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    public static function logErro(string $mensagem, array $contexto = []): void
    {
        $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem;

        if ($contexto !== []) {
            $linha .= ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        error_log($linha . PHP_EOL, 3, APP_ROOT . '/asaas-error.log');
    }

    private function localizarCliente(string $externalReference): ?array
    {
        $resposta = $this->request('GET', '/customers?externalReference=' . rawurlencode($externalReference));
        $clientes = $resposta['data'] ?? [];

        return is_array($clientes) && isset($clientes[0]) && is_array($clientes[0]) ? $clientes[0] : null;
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensao PHP cURL nao esta habilitada.');
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel iniciar conexao com o Asaas.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'access_token: ' . $this->apiKey,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $erro !== '') {
            throw new RuntimeException('Falha ao conectar ao Asaas: ' . $erro);
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Resposta invalida do Asaas.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $mensagem = $data['errors'][0]['description'] ?? $data['message'] ?? 'Erro retornado pelo Asaas.';
            throw new RuntimeException((string) $mensagem);
        }

        return $data;
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
                    status_pagamento = EXCLUDED.status_pagamento,
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
            ],
            'PAYMENT_DELETED' => [
                'pagamento' => 'cancelado',
                'reserva' => 'cancelada',
            ],
            'PAYMENT_REFUNDED' => [
                'pagamento' => 'estornado',
                'reserva' => 'cancelada',
            ],
            default => [
                'pagamento' => 'pendente',
                'reserva' => $chave === 'PAYMENT_CREATED' || $chave === 'PAYMENT_OVERDUE'
                    ? 'aguardando_pagamento'
                    : null,
            ],
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
