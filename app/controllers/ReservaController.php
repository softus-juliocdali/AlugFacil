<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\AsaasHelper;
use App\Models\Reserva;
use Throwable;

final class ReservaController extends Controller
{
    public function create(string $chacaraId): void
    {
        Auth::requireRole('cliente');

        $id = $this->validarId($chacaraId);
        $model = new Reserva();
        $chacara = $model->buscarChacaraParaReserva($id);

        if ($chacara === null) {
            $this->notFound();
        }

        $chacara = $this->corrigirCodificacao($chacara);
        $dataInicio = old('data_inicio', $this->validarDataQuery('data_inicio'));
        $dataFim = old('data_fim', $this->validarDataQuery('data_fim'));
        $quantidadeDiarias = 0;
        $valorTotal = 0.0;

        if (Reserva::periodoValido($dataInicio, $dataFim)) {
            $quantidadeDiarias = Reserva::calcularDiarias($dataInicio, $dataFim);
            $valorTotal = round($quantidadeDiarias * (float) $chacara['valor_diaria'], 2);
        }

        $this->view('public/reserva_criar', [
            'title' => 'Reservar ' . $chacara['nome'] . ' | Alug Fácil',
            'chacara' => $chacara,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'quantidadeDiarias' => $quantidadeDiarias,
            'valorTotal' => $valorTotal,
            'minDataInicio' => date('Y-m-d'),
        ]);
    }

    public function store(string $chacaraId): void
    {
        Auth::requireRole('cliente');
        verify_csrf();

        $id = $this->validarId($chacaraId);
        $dataInicio = trim((string) ($_POST['data_inicio'] ?? ''));
        $dataFim = trim((string) ($_POST['data_fim'] ?? ''));
        set_old([
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
        ]);

        $model = new Reserva();
        $chacara = $model->buscarChacaraParaReserva($id);

        if ($chacara === null) {
            $this->notFound();
        }

        if (!Reserva::periodoValido($dataInicio, $dataFim)) {
            flash('error', 'Informe uma data inicial futura e menor que a data final.');
            $this->redirect('/reserva/criar/' . $id);
        }

        if ($model->existeIndisponibilidade($id, $dataInicio, $dataFim)) {
            flash('error', 'O período escolhido possui data indisponível ou bloqueada.');
            $this->redirect('/reserva/criar/' . $id);
        }

        if ($model->existeConflitoReserva($id, $dataInicio, $dataFim)) {
            flash('error', 'Já existe uma reserva confirmada ou em andamento para este período.');
            $this->redirect('/reserva/criar/' . $id);
        }

        $quantidadeDiarias = Reserva::calcularDiarias($dataInicio, $dataFim);
        $valorDiaria = (float) $chacara['valor_diaria'];
        $valorTotal = round($quantidadeDiarias * $valorDiaria, 2);

        try {
            $reserva = $model->criar([
                'usuario_id' => (int) Auth::user()['id'],
                'proprietario_id' => (int) $chacara['proprietario_id'],
                'chacara_id' => $id,
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'quantidade_diarias' => $quantidadeDiarias,
                'valor_diaria' => $valorDiaria,
                'valor_total' => $valorTotal,
            ]);

            $this->gerarCobrancaAsaas($model, (int) $reserva['id']);
            clear_old();
            flash('success', 'Reserva criada. Finalize o pagamento para confirmar sua estadia.');
            $this->redirect('/reserva/confirmacao/' . $reserva['id']);
        } catch (Throwable) {
            flash('error', 'Não foi possível criar a reserva agora. Tente novamente.');
            $this->redirect('/reserva/criar/' . $id);
        }
    }

    public function confirmation(string $reservaId): void
    {
        Auth::requireRole('cliente');

        $id = $this->validarId($reservaId);
        $reserva = (new Reserva())->buscarConfirmacao($id, (int) Auth::user()['id']);

        if ($reserva === null) {
            $this->notFound();
        }

        $reserva = $this->corrigirCodificacao($reserva);

        $this->view('public/reserva_confirmacao', [
            'title' => 'Confirmação da reserva #' . $id . ' | Alug Fácil',
            'reserva' => $reserva,
        ]);
    }

    public function retryPayment(string $reservaId): void
    {
        Auth::requireRole('cliente'); verify_csrf(); $id=$this->validarId($reservaId); $model=new Reserva();
        $reserva=$model->buscarConfirmacao($id,(int)Auth::user()['id']);
        if(!$reserva){$this->notFound();}
        if($reserva['status_reserva']!=='aguardando_pagamento'||$reserva['status_pagamento']==='pago'||empty($reserva['expira_em'])||strtotime($reserva['expira_em'])<=time()||!empty($reserva['id_cobranca_asaas'])){flash('error','Esta reserva nao permite gerar uma nova cobranca.');$this->redirect('/reserva/confirmacao/'.$id);}
        $this->gerarCobrancaAsaas($model,$id); $this->redirect('/reserva/confirmacao/'.$id);
    }

    private function validarId(string $id): int
    {
        $validado = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validado === false) {
            $this->notFound();
        }

        return (int) $validado;
    }

    private function gerarCobrancaAsaas(Reserva $model, int $reservaId): void
    {
        $helper = new AsaasHelper();

        if (!$helper->configurado()) {
            flash('error', 'Reserva criada, mas o pagamento online ainda nao esta configurado. Entre em contato com o suporte.');
            return;
        }

        $reserva = $model->buscarParaCobranca($reservaId);

        if ($reserva === null) {
            flash('error', 'Reserva criada, mas nao foi possivel preparar os dados de pagamento.');
            return;
        }

        try {
            $cobranca = $helper->criarCobranca([
                'id' => (int) $reserva['id'],
                'valor_total' => (float) $reserva['valor_total'],
                'data_inicio' => (string) $reserva['data_inicio'],
                'data_fim' => (string) $reserva['data_fim'],
                'data_vencimento' => date('Y-m-d', strtotime('+1 day')),
                'chacara_nome' => (string) $reserva['chacara_nome'],
                'cliente' => [
                    'id' => (int) $reserva['cliente_id'],
                    'nome' => (string) $reserva['cliente_nome'],
                    'email' => (string) $reserva['cliente_email'],
                    'telefone' => (string) ($reserva['cliente_telefone'] ?? ''),
                ],
            ]);

            $linkPagamento = (string) ($cobranca['invoiceUrl'] ?? $cobranca['bankSlipUrl'] ?? '');

            if (($cobranca['id'] ?? '') === '' || $linkPagamento === '') {
                throw new \RuntimeException('Cobranca criada sem link de pagamento.');
            }

            $model->atualizarCobrancaAsaas($reservaId, (string) $cobranca['id'], $linkPagamento);
        } catch (Throwable $exception) {
            AsaasHelper::logErro('Erro ao criar cobranca da reserva #' . $reservaId . ': ' . $exception->getMessage());
            flash('error', 'Reserva criada, mas nao foi possivel gerar o link de pagamento agora. Tente novamente mais tarde ou fale com o suporte.');
        }
    }

    private function validarDataQuery(string $campo): string
    {
        $valor = trim((string) ($_GET[$campo] ?? ''));
        if ($valor === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : '';
    }

    private function corrigirCodificacao(array $dados): array
    {
        foreach ($dados as $campo => $valor) {
            if (is_string($valor) && str_contains($valor, 'Ã')) {
                $corrigido = mb_convert_encoding($valor, 'Windows-1252', 'UTF-8');
                if (mb_check_encoding($corrigido, 'UTF-8')) {
                    $dados[$campo] = $corrigido;
                }
            }
        }

        return $dados;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Reserva não encontrada']);
        exit;
    }
}
