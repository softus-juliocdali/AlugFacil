<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Chacara;
use App\Models\Reserva;
use App\Models\User;
use Throwable;

final class PanelController extends Controller
{
    public function cliente(): void
    {
        $this->redirect('/cliente/historico');
    }

    public function favoritosCliente(): void
    {
        Auth::requireRole('cliente');

        $favoritos = (new Chacara())->listarFavoritosDoUsuario((int) Auth::user()['id']);

        $this->view('cliente/favoritos', [
            'title' => 'Favoritos',
            'panelRole' => 'cliente',
            'favoritos' => $this->corrigirCodificacaoLista($favoritos),
        ], 'panel');
    }

    public function historicoCliente(): void
    {
        Auth::requireRole('cliente');

        $reservas = (new Reserva())->listarPorCliente((int) Auth::user()['id']);

        $this->view('cliente/historico', [
            'title' => 'Historico de reservas',
            'panelRole' => 'cliente',
            'reservas' => $this->corrigirCodificacaoLista($reservas),
        ], 'panel');
    }

    public function reservaCliente(string $reservaId): void
    {
        Auth::requireRole('cliente');

        $id = $this->validarId($reservaId);
        $reserva = (new Reserva())->buscarDetalheCliente($id, (int) Auth::user()['id']);

        if ($reserva === null) {
            $this->notFound();
        }

        $this->view('cliente/reserva', [
            'title' => 'Reserva #' . $id,
            'panelRole' => 'cliente',
            'reserva' => $this->corrigirCodificacao($reserva),
            'historico' => (new Reserva())->historico($id),
        ], 'panel');
    }

    public function meusDadosCliente(): void
    {
        Auth::requireRole('cliente');

        $usuario = (new User())->findById((int) Auth::user()['id']);

        if ($usuario === null) {
            Auth::logout();
            $this->redirect('/login');
        }

        $this->view('cliente/meus_dados', [
            'title' => 'Meus dados',
            'panelRole' => 'cliente',
            'usuario' => $this->corrigirCodificacao($usuario),
        ], 'panel');
    }

    public function atualizarMeusDadosCliente(): void
    {
        Auth::requireRole('cliente');
        verify_csrf();

        $userId = (int) Auth::user()['id'];
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $telefone = trim((string) ($_POST['telefone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        set_old([
            'nome' => $nome,
            'telefone' => $telefone,
            'email' => $email,
        ], 'cliente');

        if (mb_strlen($nome) < 2) {
            flash('error', 'Informe seu nome completo.');
            $this->redirect('/cliente/meus-dados');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Informe um e-mail valido.');
            $this->redirect('/cliente/meus-dados');
        }

        if ($senha !== '' && mb_strlen($senha) < 6) {
            flash('error', 'A nova senha deve ter pelo menos 6 caracteres.');
            $this->redirect('/cliente/meus-dados');
        }

        $model = new User();

        if ($model->emailExistsForAnotherUser($email, $userId)) {
            flash('error', 'Este e-mail ja esta em uso por outra conta.');
            $this->redirect('/cliente/meus-dados');
        }

        try {
            $model->updateClientData($userId, [
                'nome' => $nome,
                'telefone' => $telefone,
                'email' => $email,
                'senha' => $senha,
            ]);
            clear_old();
            flash('success', 'Seus dados foram atualizados com sucesso.');
            $this->redirect('/cliente/meus-dados');
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar seus dados agora.');
            $this->redirect('/cliente/meus-dados');
        }
    }

    public function dadosCadastraisProprietario(): void
    {
        Auth::requireRole('proprietario');

        $usuario = (new User())->findById((int) Auth::user()['id']);
        $proprietario = (new User())->findOwnerByUserId((int) Auth::user()['id']);

        if ($usuario === null || $proprietario === null) {
            Auth::logout();
            flash('error', 'Nao foi possivel localizar seu cadastro de proprietario.');
            $this->redirect('/login');
        }

        $this->view('proprietario/dados_cadastrais', [
            'title' => 'Dados cadastrais',
            'panelRole' => 'proprietario',
            'usuario' => $this->corrigirCodificacao($usuario),
            'proprietario' => $this->corrigirCodificacao($proprietario),
        ], 'panel');
    }

    public function atualizarDadosCadastraisProprietario(): void
    {
        Auth::requireRole('proprietario');
        verify_csrf();

        $userId = (int) Auth::user()['id'];
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $telefone = trim((string) ($_POST['telefone'] ?? ''));
        $cpf = preg_replace('/\D+/', '', (string) ($_POST['cpf'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        set_old([
            'nome' => $nome,
            'telefone' => $telefone,
            'cpf' => $this->formatarCpf($cpf),
            'email' => $email,
        ], 'proprietario');

        if (mb_strlen($nome) < 2) {
            flash('error', 'Informe seu nome completo.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        if (!$this->cpfValido($cpf)) {
            flash('error', 'Informe um CPF valido.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Informe um e-mail valido.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        if ($senha !== '' && mb_strlen($senha) < 6) {
            flash('error', 'A nova senha deve ter pelo menos 6 caracteres.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        $model = new User();

        if ($model->findOwnerByUserId($userId) === null) {
            flash('error', 'Nao foi possivel localizar seu cadastro de proprietario.');
            $this->redirect('/proprietario/dashboard');
        }

        if ($model->emailExistsForAnotherUser($email, $userId)) {
            flash('error', 'Este e-mail ja esta em uso por outra conta.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        if ($model->cpfExistsForAnotherOwner($cpf, $userId)) {
            flash('error', 'Este CPF ja esta em uso por outro proprietario.');
            $this->redirect('/proprietario/dados-cadastrais');
        }

        try {
            $model->updateOwnerData($userId, [
                'nome' => $nome,
                'telefone' => $telefone,
                'cpf' => $cpf,
                'email' => $email,
                'senha' => $senha,
            ]);

            $_SESSION['user'] = array_merge($_SESSION['user'], [
                'nome' => $nome,
                'email' => $email,
            ]);

            clear_old();
            flash('success', 'Seus dados cadastrais foram atualizados com sucesso.');
            $this->redirect('/proprietario/dados-cadastrais');
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar seus dados cadastrais agora.');
            $this->redirect('/proprietario/dados-cadastrais');
        }
    }

    public function proprietario(): void
    {
        $proprietario = Auth::requireProprietarioOperacional();

        $proprietarioId = (int) $proprietario['id'];
        $reservaModel = new Reserva();
        $chacaraModel = new Chacara();
        $resumo = $reservaModel->resumoPorProprietario($proprietarioId);
        $resumo['total_chacaras'] = $chacaraModel->totalPorProprietario($proprietarioId);

        $this->view('proprietario/dashboard', [
            'title' => 'Dashboard do proprietario',
            'panelRole' => 'proprietario',
            'proprietario' => $this->corrigirCodificacao($proprietario),
            'resumo' => $resumo,
            'ultimasReservas' => $this->corrigirCodificacaoLista(
                $reservaModel->listarUltimasPorProprietario($proprietarioId, 5)
            ),
            'disponibilidades' => $this->corrigirCodificacaoLista(
                $chacaraModel->listarDisponibilidadeResumoPorProprietario($proprietarioId, 8)
            ),
        ], 'panel');
    }

    public function statusProprietario(): void
    {
        Auth::requireProprietarioAutenticado();
        $this->redirect('/proprietario/dashboard');
    }

    public function admin(): void
    {
        Auth::requireRole('admin');

        $chacaras = (new Chacara())->resumoAdministrativo();
        $usuarios = (new User())->resumoAdministrativo();
        $reservas = new Reserva();
        $resumoReservas = $reservas->resumoAdministrativo();

        $this->view('admin/dashboard', [
            'title' => 'Dashboard administrativo',
            'panelRole' => 'admin',
            'resumo' => array_merge($chacaras, $usuarios, $resumoReservas),
            'ultimasReservas' => $this->corrigirCodificacaoLista(
                $reservas->listarUltimasAdministrativas()
            ),
        ], 'panel');
    }

    private function validarId(string $id): int
    {
        $validado = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validado === false) {
            $this->notFound();
        }

        return (int) $validado;
    }

    private function corrigirCodificacaoLista(array $itens): array
    {
        return array_map(fn (array $item): array => $this->corrigirCodificacao($item), $itens);
    }

    private function corrigirCodificacao(array $dados): array
    {
        foreach ($dados as $campo => $valor) {
            if (is_string($valor) && str_contains($valor, 'Ãƒ')) {
                $corrigido = mb_convert_encoding($valor, 'Windows-1252', 'UTF-8');
                if (mb_check_encoding($corrigido, 'UTF-8')) {
                    $dados[$campo] = $corrigido;
                }
            }
        }

        return $dados;
    }

    private function cpfValido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($tamanho = 9; $tamanho < 11; $tamanho++) {
            $soma = 0;
            for ($indice = 0; $indice < $tamanho; $indice++) {
                $soma += (int) $cpf[$indice] * (($tamanho + 1) - $indice);
            }

            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$tamanho] !== $digito) {
                return false;
            }
        }

        return true;
    }

    private function formatarCpf(string $cpf): string
    {
        if (strlen($cpf) !== 11) {
            return $cpf;
        }

        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Reserva nao encontrada']);
        exit;
    }
}
