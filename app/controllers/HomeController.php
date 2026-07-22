<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Chacara;
use Throwable;

final class HomeController extends Controller
{
    public function index(): void
    {
        $filtros = $this->normalizarFiltros($_GET);
        $erroFiltro = null;
        $erroBanco = null;
        $properties = [];
        $favoriteChacaraIds = [];
        $model = new Chacara();

        if (!Chacara::periodoValido($filtros['data_inicio'], $filtros['data_fim'])) {
            $erroFiltro = 'Informe uma data de saída posterior à data de entrada.';
        } else {
            try {
                $properties = $model->buscarDisponiveis($filtros);
                $properties = array_map([$this, 'corrigirCodificacaoLegada'], $properties);

                if (Auth::check() && (Auth::user()['role'] ?? null) === 'cliente') {
                    $favoriteChacaraIds = $model->favoritosDoUsuario((int) Auth::user()['id']);
                }
            } catch (Throwable) {
                $erroBanco = 'Não foi possível carregar as chácaras agora. Tente novamente em instantes.';
            }
        }

        $this->view('public/home', [
            'title' => 'Alug Fácil | Encontre a chácara perfeita',
            'properties' => $properties,
            'filtros' => $filtros,
            'erroFiltro' => $erroFiltro,
            'erroBanco' => $erroBanco,
            'favoriteChacaraIds' => $favoriteChacaraIds,
        ]);
    }

    private function normalizarFiltros(array $entrada): array
    {
        $texto = static fn (mixed $valor): string => mb_substr(trim((string) $valor), 0, 100);
        $numero = static function (mixed $valor): ?float {
            $valor = str_replace(',', '.', trim((string) $valor));
            if ($valor === '' || !is_numeric($valor)) {
                return null;
            }

            return max(0, min((float) $valor, 9999999.99));
        };

        $inicio = $texto($entrada['data_inicio'] ?? '');
        $fim = $texto($entrada['data_fim'] ?? '');
        $ordenacoes = ['relevancia', 'menor_preco', 'maior_preco', 'nome', 'recentes'];
        $ordenacao = $texto($entrada['ordenacao'] ?? 'relevancia');
        $tiposImovel = ['chacara', 'sitio', 'area_lazer'];
        $tipoImovel = $texto($entrada['tipo_imovel'] ?? '');

        return [
            'valor_min' => $numero($entrada['valor_min'] ?? null),
            'valor_max' => $numero($entrada['valor_max'] ?? null),
            'tipo_imovel' => in_array($tipoImovel, $tiposImovel, true) ? $tipoImovel : '',
            'cidade' => $texto($entrada['cidade'] ?? $entrada['destino'] ?? ''),
            'regiao' => $texto($entrada['regiao'] ?? ''),
            'data_inicio' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) ? $inicio : '',
            'data_fim' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim) ? $fim : '',
            'ordenacao' => in_array($ordenacao, $ordenacoes, true) ? $ordenacao : 'relevancia',
        ];
    }

    private function corrigirCodificacaoLegada(array $property): array
    {
        foreach (['nome', 'descricao', 'cidade', 'regiao'] as $campo) {
            $valor = (string) ($property[$campo] ?? '');

            if ($valor !== '' && (str_contains($valor, 'Ã') || str_contains($valor, 'Â'))) {
                $corrigido = mb_convert_encoding($valor, 'Windows-1252', 'UTF-8');
                if (mb_check_encoding($corrigido, 'UTF-8')) {
                    $property[$campo] = $corrigido;
                }
            }
        }

        return $property;
    }
}
