<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Reserva;
use DateTimeImmutable;

final class OwnerBillingController extends Controller
{
    public function index(): void
    {
        $proprietarioId = $this->proprietarioId();
        $periodo = (string) ($_GET['periodo'] ?? 'mes');
        [$inicio, $fim, $periodo] = $this->resolverPeriodo($periodo);
        $dados = (new Reserva())->faturamentoPorProprietario($proprietarioId, $inicio, $fim);
        $q=Database::getConnection()->prepare("SELECT COALESCE(SUM(valor_repasse_centavos),0) previsto,COALESCE(SUM(valor_repasse_centavos) FILTER(WHERE status_local='concluido'),0) recebido,COALESCE(SUM(valor_repasse_centavos) FILTER(WHERE status_local NOT IN ('concluido','cancelado')),0) pendente FROM repasses_reservas WHERE proprietario_id=:p AND criado_em::date BETWEEN :i AND :f");$q->execute(['p'=>$proprietarioId,'i'=>$inicio,'f'=>$fim]);$splitResumo=$q->fetch();
        $s=Database::getConnection()->prepare("SELECT reserva_id,status_local,valor_repasse_centavos,repasse_liberavel_em,concluido_em,conciliacao_manual FROM repasses_reservas WHERE proprietario_id=:p");$s->execute(['p'=>$proprietarioId]);$repasses=[];foreach($s->fetchAll()as$x)$repasses[(int)$x['reserva_id']]=$x;
        foreach(['reservas_principais','reservas_pendentes','reservas']as$k)foreach($dados[$k]as&$r)$r['repasse']=$repasses[(int)$r['id']]??null;

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
            'splitResumo'=>$splitResumo,
        ], 'panel');
    }

    private function proprietarioId(): int
    {
        $proprietario = Auth::requireProprietarioOperacional();
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
