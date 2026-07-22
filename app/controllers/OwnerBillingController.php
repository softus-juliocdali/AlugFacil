<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Reserva;
use App\Models\User;
use DateTimeImmutable;

final class OwnerBillingController extends Controller
{
    public function index(): void
    {
        $proprietarioId = $this->proprietarioId();
        $periodo = (string) ($_GET['periodo'] ?? 'mes');
        [$inicio, $fim, $periodo] = $this->resolverPeriodo($periodo);
        $dados = (new Reserva())->faturamentoPorProprietario($proprietarioId, $inicio, $fim);

        $this->view('proprietario/faturamento/index', [
            'title' => 'Faturamento',
            'panelRole' => 'proprietario',
            'periodo' => $periodo,
            'inicio' => $inicio,
            'fim' => $fim,
            'resumo' => $dados['resumo'],
            'reservasPrincipais' => $dados['reservas_principais'],
            'reservasPendentes' => $dados['reservas_pendentes'],
            'reservas' => $dados['reservas'],
        ], 'panel');
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

    private function resolverPeriodo(string $periodo): array
    {
        $hoje = new DateTimeImmutable('today');

        return match ($periodo) {
            'semana' => [
                $hoje->modify('monday this week')->format('Y-m-d'),
                $hoje->modify('sunday this week')->format('Y-m-d'),
                'semana',
            ],
            'semestre' => [
                $hoje->setDate((int) $hoje->format('Y'), (int) $hoje->format('n') <= 6 ? 1 : 7, 1)->format('Y-m-d'),
                $hoje->setDate((int) $hoje->format('Y'), (int) $hoje->format('n') <= 6 ? 6 : 12, 1)->modify('last day of this month')->format('Y-m-d'),
                'semestre',
            ],
            'ano' => [
                $hoje->format('Y-01-01'),
                $hoje->format('Y-12-31'),
                'ano',
            ],
            'personalizado' => $this->resolverPeriodoPersonalizado($hoje),
            default => [
                $hoje->format('Y-m-01'),
                $hoje->format('Y-m-t'),
                'mes',
            ],
        };
    }

    private function resolverPeriodoPersonalizado(DateTimeImmutable $fallback): array
    {
        $inicio = trim((string) ($_GET['inicio'] ?? ''));
        $fim = trim((string) ($_GET['fim'] ?? ''));

        if ($this->dataValida($inicio) && $this->dataValida($fim) && $fim >= $inicio) {
            return [$inicio, $fim, 'personalizado'];
        }

        return [$fallback->format('Y-m-01'), $fallback->format('Y-m-t'), 'mes'];
    }

    private function dataValida(string $data): bool
    {
        $validada = DateTimeImmutable::createFromFormat('!Y-m-d', $data);

        return $validada !== false && $validada->format('Y-m-d') === $data;
    }
}
