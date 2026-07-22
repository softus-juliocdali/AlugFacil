<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Chacara;
use App\Models\User;
use DateInterval;
use DateTimeImmutable;
use Throwable;

final class OwnerAvailabilityController extends Controller
{
    public function index(): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacaras = (new Chacara())->listarPorProprietario($proprietarioId);

        if (!empty($chacaras)) {
            $this->redirect('/proprietario/disponibilidade/' . (int) $chacaras[0]['id']);
        }

        $this->view('proprietario/disponibilidade/index', [
            'title' => 'Disponibilidade',
            'panelRole' => 'proprietario',
            'chacaras' => [],
            'chacara' => null,
            'dias' => [],
            'inicio' => date('Y-m-01'),
            'fim' => date('Y-m-t'),
        ], 'panel');
    }

    public function show(string $chacara_id): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacaraId = $this->validarId($chacara_id);
        $model = new Chacara();
        $chacara = $model->buscarDoProprietario($chacaraId, $proprietarioId);

        if ($chacara === null) {
            $this->notFound();
        }

        $inicio = $this->normalizarMes((string) ($_GET['mes'] ?? ''));
        $fim = $inicio->modify('last day of this month');
        $eventos = $model->buscarCalendarioProprietario(
            $chacaraId,
            $proprietarioId,
            $inicio->format('Y-m-d'),
            $fim->format('Y-m-d')
        );

        $this->view('proprietario/disponibilidade/index', [
            'title' => 'Disponibilidade',
            'panelRole' => 'proprietario',
            'chacaras' => $model->listarPorProprietario($proprietarioId),
            'chacara' => $chacara,
            'dias' => $this->montarDias($inicio, $fim, $eventos),
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
            'mesAtual' => $inicio->format('Y-m'),
            'mesAnterior' => $inicio->modify('-1 month')->format('Y-m'),
            'proximoMes' => $inicio->modify('+1 month')->format('Y-m'),
        ], 'panel');
    }

    public function save(): void
    {
        $proprietarioId = $this->proprietarioId();
        verify_csrf();

        $chacaraId = $this->validarId((string) ($_POST['chacara_id'] ?? ''));
        $acao = (string) ($_POST['acao'] ?? 'bloquear');
        $inicio = trim((string) ($_POST['data_inicio'] ?? ''));
        $fim = trim((string) ($_POST['data_fim'] ?? $inicio));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $redirect = '/proprietario/disponibilidade/' . $chacaraId;

        $model = new Chacara();

        if ($model->buscarDoProprietario($chacaraId, $proprietarioId) === null) {
            $this->notFound();
        }

        if (!$this->periodoValido($inicio, $fim)) {
            flash('error', 'Informe um periodo valido.');
            $this->redirect($redirect);
        }

        try {
            if ($acao === 'liberar') {
                $removidos = $model->liberarPeriodoBloqueado($chacaraId, $proprietarioId, $inicio, $fim);
                flash('success', $removidos > 0 ? 'Datas bloqueadas liberadas.' : 'Nenhuma data bloqueada encontrada para liberar.');
                $this->redirect($redirect . '?mes=' . substr($inicio, 0, 7));
            }

            if ($model->existeReservaConfirmadaNoPeriodo($chacaraId, $proprietarioId, $inicio, $this->diaSeguinte($fim))) {
                flash('error', 'Nao e possivel bloquear datas com reserva confirmada.');
                $this->redirect($redirect . '?mes=' . substr($inicio, 0, 7));
            }

            $alterados = $model->bloquearPeriodo($chacaraId, $proprietarioId, $inicio, $fim, $observacao);
            flash('success', $alterados > 0 ? 'Periodo bloqueado com sucesso.' : 'Nenhuma data foi alterada.');
            $this->redirect($redirect . '?mes=' . substr($inicio, 0, 7));
        } catch (Throwable) {
            flash('error', 'Nao foi possivel salvar a disponibilidade agora.');
            $this->redirect($redirect);
        }
    }

    private function proprietarioId(): int
    {
        Auth::requireRole('proprietario');
        $proprietario = (new User())->findOwnerByUserId((int) Auth::user()['id']);

        if ($proprietario === null) {
            flash('error', 'Nao foi possivel localizar seu cadastro de proprietario.');
            $this->redirect('/login');
        }

        return (int) $proprietario['id'];
    }

    private function montarDias(DateTimeImmutable $inicio, DateTimeImmutable $fim, array $eventos): array
    {
        $mapa = [];

        foreach ($eventos as $evento) {
            $data = $evento['data'];

            if (($mapa[$data]['status'] ?? '') === 'reservado') {
                continue;
            }

            $mapa[$data] = [
                'status' => $evento['status'],
                'observacao' => $evento['observacao'] ?? '',
                'reserva_id' => $evento['reserva_id'] ?? null,
            ];
        }

        $dias = [];
        $cursor = $inicio;

        while ($cursor <= $fim) {
            $data = $cursor->format('Y-m-d');
            $evento = $mapa[$data] ?? ['status' => 'disponivel', 'observacao' => '', 'reserva_id' => null];
            $dias[] = [
                'data' => $data,
                'dia' => $cursor->format('d'),
                'semana' => $this->nomeSemana($cursor),
                'status' => $evento['status'],
                'observacao' => $evento['observacao'],
                'reserva_id' => $evento['reserva_id'],
            ];
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $dias;
    }

    private function normalizarMes(string $mes): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            $data = DateTimeImmutable::createFromFormat('!Y-m-d', $mes . '-01');

            if ($data !== false) {
                return $data;
            }
        }

        return new DateTimeImmutable('first day of this month');
    }

    private function periodoValido(string $inicio, string $fim): bool
    {
        $dataInicio = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $dataFim = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);

        return $dataInicio !== false
            && $dataFim !== false
            && $dataInicio->format('Y-m-d') === $inicio
            && $dataFim->format('Y-m-d') === $fim
            && $dataFim >= $dataInicio;
    }

    private function diaSeguinte(string $data): string
    {
        return (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d');
    }

    private function nomeSemana(DateTimeImmutable $data): string
    {
        return ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'][(int) $data->format('w')];
    }

    private function validarId(string $id): int
    {
        $validado = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validado === false) {
            $this->notFound();
        }

        return (int) $validado;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Chacara nao encontrada']);
        exit;
    }
}
