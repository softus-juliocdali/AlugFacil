<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\GoogleMapsHelper;
use App\Models\Chacara;
use DateInterval;
use DateTimeImmutable;
use Throwable;

final class ChacaraController extends Controller
{
    public function show(string $id): void
    {
        $chacaraId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($chacaraId === false) {
            $this->notFound();
        }

        try {
            $model = new Chacara();
            $chacara = $model->buscarPerfil((int) $chacaraId);
            if ($chacara === null) {
                $this->notFound();
            }

            $fotos = $model->buscarFotos((int) $chacaraId);
            $avaliacoes = $model->buscarAvaliacoesAtivas((int) $chacaraId);
            $inicioCalendario = new DateTimeImmutable('first day of this month');
            $fimCalendario = $inicioCalendario->add(new DateInterval('P6M'))->modify('-1 day');
            $indisponibilidades = $model->buscarDatasIndisponiveis(
                (int) $chacaraId,
                $inicioCalendario->format('Y-m-d'),
                $fimCalendario->format('Y-m-d')
            );
            $reservaAvaliavel = null;

            if (Auth::check() && (Auth::user()['role'] ?? null) === 'cliente') {
                $reservaAvaliavel = $model->reservaFinalizadaDisponivelParaAvaliacao(
                    (int) $chacaraId,
                    (int) Auth::user()['id']
                );
            }

            $chacara = $this->corrigirCodificacao($chacara);
            $avaliacoes = array_map([$this, 'corrigirCodificacao'], $avaliacoes);
            $media = $avaliacoes === [] ? 0.0 : array_sum(array_column($avaliacoes, 'nota')) / count($avaliacoes);
            $googleMaps = new GoogleMapsHelper();

            $this->view('public/perfil_chacara', [
                'title' => $chacara['nome'] . ' | Alug Fácil',
                'chacara' => $chacara,
                'fotos' => $fotos,
                'avaliacoes' => $avaliacoes,
                'notaMedia' => $media,
                'reservaAvaliavel' => $reservaAvaliavel,
                'calendarios' => $this->montarCalendarios($inicioCalendario, 6, $indisponibilidades),
                'googleMapsConfigurado' => $googleMaps->configurado(),
                'googleMapsEmbedUrl' => $googleMaps->embedUrl($chacara['latitude'] ?? null, $chacara['longitude'] ?? null),
            ]);
        } catch (Throwable) {
            http_response_code(500);
            $this->view('public/404', [
                'title' => 'Não foi possível carregar a chácara',
                'message' => 'Não foi possível carregar esta chácara agora. Tente novamente em instantes.',
            ]);
        }
    }

    public function reserve(string $id): void
    {
        Auth::requireLogin();
        $chacaraId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($chacaraId === false || (new Chacara())->buscarPerfil((int) $chacaraId) === null) {
            $this->notFound();
        }

        $this->redirect('/reserva/criar/' . (int) $chacaraId);
    }

    public function review(string $id): void
    {
        Auth::requireRole('cliente');
        verify_csrf();

        $chacaraId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nota = filter_var($_POST['nota'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5],
        ]);
        $comentario = trim((string) ($_POST['comentario'] ?? ''));

        if ($chacaraId === false || $nota === false || mb_strlen($comentario) < 3 || mb_strlen($comentario) > 1500) {
            flash('error', 'Informe uma nota de 1 a 5 e um comentário válido.');
            $this->redirect('/chacara/' . (int) $id . '#avaliacoes');
        }

        $model = new Chacara();
        $reservaId = $model->reservaFinalizadaDisponivelParaAvaliacao(
            (int) $chacaraId,
            (int) Auth::user()['id']
        );

        if ($reservaId === null) {
            flash('error', 'Somente clientes com reserva finalizada podem avaliar esta chácara.');
            $this->redirect('/chacara/' . (int) $chacaraId . '#avaliacoes');
        }

        try {
            $model->criarAvaliacao((int) Auth::user()['id'], (int) $chacaraId, $reservaId, (int) $nota, $comentario);
            flash('success', 'Sua avaliação foi publicada.');
        } catch (Throwable) {
            flash('error', 'Não foi possível publicar sua avaliação.');
        }

        $this->redirect('/chacara/' . (int) $chacaraId . '#avaliacoes');
    }

    public function favorite(string $id): void
    {
        Auth::requireRole('cliente');
        verify_csrf();

        $chacaraId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $redirectTo = $this->validarRetorno((string) ($_POST['redirect_to'] ?? '/#chacaras'));

        if ($chacaraId === false) {
            flash('error', 'Chacara invalida.');
            $this->redirect($redirectTo);
        }

        $model = new Chacara();
        $usuarioId = (int) Auth::user()['id'];
        $perfilDisponivel = $model->buscarPerfil((int) $chacaraId) !== null;
        $jaFavoritou = $model->usuarioFavoritou($usuarioId, (int) $chacaraId);

        if (!$perfilDisponivel && !$jaFavoritou) {
            flash('error', 'Chacara nao encontrada ou indisponivel.');
            $this->redirect($redirectTo);
        }

        try {
            $favoritado = $model->alternarFavorito($usuarioId, (int) $chacaraId);
            flash('success', $favoritado ? 'Chacara adicionada aos favoritos.' : 'Chacara removida dos favoritos.');
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar seus favoritos agora.');
        }

        $this->redirect($redirectTo);
    }

    private function montarCalendarios(DateTimeImmutable $inicio, int $quantidade, array $bloqueios): array
    {
        $statusPorData = [];
        foreach ($bloqueios as $bloqueio) {
            $statusPorData[$bloqueio['data']] = $bloqueio['status'];
        }

        $nomesMeses = [
            1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];
        $calendarios = [];

        for ($indice = 0; $indice < $quantidade; $indice++) {
            $mes = $inicio->add(new DateInterval('P' . $indice . 'M'));
            $dias = [];

            for ($dia = 1; $dia <= (int) $mes->format('t'); $dia++) {
                $data = $mes->setDate((int) $mes->format('Y'), (int) $mes->format('n'), $dia);
                $dias[] = [
                    'numero' => $dia,
                    'data' => $data->format('Y-m-d'),
                    'status' => $statusPorData[$data->format('Y-m-d')] ?? 'disponivel',
                    'passado' => $data < new DateTimeImmutable('today'),
                ];
            }

            $calendarios[] = [
                'titulo' => $nomesMeses[(int) $mes->format('n')] . ' ' . $mes->format('Y'),
                'espacos' => (int) $mes->format('N') - 1,
                'dias' => $dias,
            ];
        }

        return $calendarios;
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

    private function validarRetorno(string $retorno): string
    {
        $retorno = trim($retorno);
        if ($retorno === '' || !str_starts_with($retorno, '/') || str_starts_with($retorno, '//')) {
            return '/#chacaras';
        }

        return mb_substr($retorno, 0, 300);
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Chácara não encontrada']);
        exit;
    }
}
