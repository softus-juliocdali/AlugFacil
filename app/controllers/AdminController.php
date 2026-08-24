<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\User;
use App\Models\Chacara;
use App\Models\ConfiguracaoMensalidadeAnuncio;
use App\Models\MensalidadeAnuncio;
use App\Services\MensalidadeAnuncioService;
use App\Services\AffiliateMonthlyFeePolicy;
use App\Services\PrecificacaoReservaService;
use RuntimeException;
use Throwable;

final class AdminController extends Controller
{
    public function administradores(): void
    {
        Auth::requireRole('admin');

        $busca = trim((string) ($_GET['busca'] ?? ''));
        $model = new User();

        $this->view('admin/administradores/index', [
            'title' => 'Administradores',
            'panelRole' => 'admin',
            'busca' => $busca,
            'administradores' => $this->corrigirCodificacaoLista(
                $model->listarAdministradores($busca)
            ),
        ], 'panel');
    }

    public function criarAdministrador(): void
    {
        Auth::requireRole('admin');

        $this->view('admin/administradores/form', [
            'title' => 'Novo administrador',
            'panelRole' => 'admin',
            'administrador' => null,
            'action' => url('/admin/administradores/criar'),
            'modo' => 'criar',
        ], 'panel');
    }

    public function salvarAdministrador(): void
    {
        Auth::requireRole('admin');
        verify_csrf();

        $data = $this->dadosAdministrador(true);
        set_old([
            'nome' => $data['nome'],
            'telefone' => $data['telefone'],
            'email' => $data['email'],
            'status' => $data['status'],
        ]);
        $erro = $this->validarAdministrador($data, true);

        if ($erro !== null) {
            flash('error', $erro);
            $this->redirect('/admin/administradores/criar');
        }

        $model = new User();
        if ($model->emailExists($data['email'])) {
            flash('error', 'Ja existe uma conta com este e-mail.');
            $this->redirect('/admin/administradores/criar');
        }

        try {
            $model->criarAdministrador($data);
        } catch (Throwable) {
            flash('error', 'Nao foi possivel criar o administrador.');
            $this->redirect('/admin/administradores/criar');
        }

        clear_old();
        flash('success', 'Administrador criado com sucesso.');
        $this->redirect('/admin/administradores');
    }

    public function editarAdministrador(string $id): void
    {
        Auth::requireRole('admin');

        $adminId = $this->validarId($id);
        $administrador = (new User())->buscarAdministrador($adminId);

        if ($administrador === null) {
            $this->notFound();
        }

        $this->view('admin/administradores/form', [
            'title' => 'Editar administrador',
            'panelRole' => 'admin',
            'administrador' => $this->corrigirCodificacao($administrador),
            'action' => url('/admin/administradores/' . $adminId . '/editar'),
            'modo' => 'editar',
        ], 'panel');
    }

    public function atualizarAdministrador(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();

        $adminId = $this->validarId($id);
        $model = new User();
        $administrador = $model->buscarAdministrador($adminId);

        if ($administrador === null) {
            $this->notFound();
        }

        $data = $this->dadosAdministrador(false);
        set_old([
            'nome' => $data['nome'],
            'telefone' => $data['telefone'],
            'email' => $data['email'],
            'status' => $data['status'],
        ]);
        $erro = $this->validarAdministrador($data, false);

        if ($erro !== null) {
            flash('error', $erro);
            $this->redirect('/admin/administradores/' . $adminId . '/editar');
        }

        if ((int) Auth::user()['id'] === $adminId && $data['status'] !== 'ativo') {
            flash('error', 'Voce nao pode bloquear sua propria conta de administrador.');
            $this->redirect('/admin/administradores/' . $adminId . '/editar');
        }

        if ($administrador['status'] === 'ativo'
            && $data['status'] !== 'ativo'
            && $model->totalAdministradoresAtivos() <= 1
        ) {
            flash('error', 'Mantenha ao menos um administrador ativo.');
            $this->redirect('/admin/administradores/' . $adminId . '/editar');
        }

        if ($model->emailExistsForAnotherUser($data['email'], $adminId)) {
            flash('error', 'Ja existe outra conta com este e-mail.');
            $this->redirect('/admin/administradores/' . $adminId . '/editar');
        }

        try {
            $model->atualizarAdministrador($adminId, $data);
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar o administrador.');
            $this->redirect('/admin/administradores/' . $adminId . '/editar');
        }

        clear_old();
        flash('success', 'Administrador atualizado com sucesso.');
        $this->redirect('/admin/administradores');
    }

    public function minhaConta(): void
    {
        Auth::requireRole('admin');

        $administrador = (new User())->buscarAdministrador((int) Auth::user()['id']);
        if ($administrador === null) {
            $this->notFound();
        }

        $this->view('admin/administradores/minha_conta', [
            'title' => 'Minha conta',
            'panelRole' => 'admin',
            'administrador' => $this->corrigirCodificacao($administrador),
        ], 'panel');
    }

    public function atualizarMinhaConta(): void
    {
        Auth::requireRole('admin');
        verify_csrf();

        $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
        $novaSenha = (string) ($_POST['senha'] ?? '');
        $confirmacao = (string) ($_POST['senha_confirmacao'] ?? '');

        $model = new User();
        $usuario = $model->findById((int) Auth::user()['id']);

        if ($usuario === null || $usuario['tipo_usuario'] !== 'admin') {
            $this->notFound();
        }

        if (!password_verify($senhaAtual, $usuario['senha_hash'])) {
            flash('error', 'Senha atual incorreta.');
            $this->redirect('/admin/minha-conta');
        }

        if (strlen($novaSenha) < 6 || $novaSenha !== $confirmacao) {
            flash('error', 'A nova senha deve ter ao menos 6 caracteres e a confirmacao deve ser igual.');
            $this->redirect('/admin/minha-conta');
        }

        $model->atualizarSenhaAdministrador((int) $usuario['id'], $novaSenha);
        flash('success', 'Senha atualizada com sucesso.');
        $this->redirect('/admin/minha-conta');
    }

    public function proprietarios(): void
    {
        Auth::requireRole('admin');

        $busca = trim((string) ($_GET['busca'] ?? ''));
        $model = new User();

        $this->view('admin/proprietarios/index', [
            'title' => 'Proprietarios',
            'panelRole' => 'admin',
            'busca' => $busca,
            'proprietarios' => $this->corrigirCodificacaoLista(
                $model->listarProprietariosAdministrativo($busca)
            ),
        ], 'panel');
    }

    public function proprietarioDetalhes(string $id): void
    {
        Auth::requireRole('admin');

        $proprietarioId = $this->validarId($id);
        $model = new User();
        $proprietario = $model->buscarProprietarioAdministrativo($proprietarioId);

        if ($proprietario === null) {
            $this->notFound();
        }

        $this->view('admin/proprietarios/show', [
            'title' => 'Detalhes do proprietario',
            'panelRole' => 'admin',
            'proprietario' => $this->corrigirCodificacao($proprietario),
            'chacaras' => $this->corrigirCodificacaoLista(
                $model->listarChacarasDoProprietarioAdministrativo($proprietarioId)
            ),
        ], 'panel');
    }

    public function atualizarStatusProprietario(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();

        $proprietarioId = $this->validarId($id);
        $status = (string) ($_POST['status'] ?? '');

        if (!in_array($status, ['pendente', 'ativo', 'rejeitado', 'bloqueado'], true)) {
            flash('error', 'Status de proprietario invalido.');
            $this->redirect('/admin/proprietarios');
        }

        $model = new User();
        $proprietario = $model->buscarProprietarioAdministrativo($proprietarioId);

        if ($proprietario === null) {
            flash('error', 'Proprietario nao encontrado.');
            $this->redirect('/admin/proprietarios');
        }

        if ((int) $proprietario['usuario_id'] === (int) Auth::user()['id']) {
            flash('error', 'Voce nao pode bloquear ou alterar o status da sua propria conta.');
            $this->redirect('/admin/proprietarios/' . $proprietarioId);
        }

        try {
            $atualizado = $model->atualizarStatusProprietarioAdministrativo(
                $proprietarioId,
                $status,
                trim((string) ($_POST['motivo'] ?? '')),
                (int) Auth::user()['id']
            );
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar o status do proprietario.');
            $this->redirect('/admin/proprietarios/' . $proprietarioId);
        }

        flash($atualizado ? 'success' : 'error', $atualizado
            ? 'Status do proprietario atualizado com sucesso.'
            : 'Transicao de status invalida ou proprietario nao encontrado.');
        $this->redirect('/admin/proprietarios/' . $proprietarioId);
    }

    public function chacaras(): void
    {
        Auth::requireRole('admin');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('admin/chacaras/index', [
            'title' => 'Imoveis',
            'panelRole' => 'admin',
            'status' => $status,
            'chacaras' => (new Chacara())->listarAdministrativo($status),
        ], 'panel');
    }

    public function chacaraDetalhes(string $id): void
    {
        Auth::requireRole('admin');
        $chacaraId = $this->validarId($id);
        $model = new Chacara();
        $chacara = $model->buscarAdministrativo($chacaraId);
        if ($chacara === null) {
            $this->notFound();
        }
        $this->view('admin/chacaras/show', [
            'title' => 'Detalhes do imovel',
            'panelRole' => 'admin',
            'chacara' => $chacara,
            'fotos' => $model->buscarFotosGerenciamento($chacaraId),
            'mensalidade' => (new MensalidadeAnuncio())->buscarAdministrativa($chacaraId),
            'historicoMensalidade' => (new MensalidadeAnuncio())->historicoAdministrativo($chacaraId),
            'cobrancasMensalidade' => (new MensalidadeAnuncio())->cobrancasAdministrativas($chacaraId),
            'valorMensalPadraoCentavos' => (new ConfiguracaoMensalidadeAnuncio())->valorPadraoCentavos(),
            'mensalidadeObrigatoriaAfiliado' => AffiliateMonthlyFeePolicy::propertyRequiresMonthlyFee($chacara),
        ], 'panel');
    }

    public function atualizarStatusChacara(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();
        $chacaraId = $this->validarId($id);
        $status = (string) ($_POST['status'] ?? '');
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if (!in_array($status, ['pendente', 'aprovada', 'rejeitada', 'bloqueada'], true)) {
            flash('error', 'Status de aprovacao invalido.');
            $this->redirect('/admin/chacaras/' . $chacaraId);
        }
        try {
            $mensalidadeAtiva = null;
            if ($status === 'aprovada') {
                $chacara = (new Chacara())->buscarAdministrativo($chacaraId);
                if ($chacara === null) {
                    $this->notFound();
                }
                $tipoMensalidade = $_POST['mensalidade'] ?? null;
                if (!is_string($tipoMensalidade)
                    || !in_array($tipoMensalidade, ['sem', 'com'], true)) {
                    throw new RuntimeException('Escolha se o imovel sera aprovado com ou sem mensalidade.');
                }

                AffiliateMonthlyFeePolicy::assertModeAllowed($chacara, $tipoMensalidade);

                $mensalidadeAtiva = $tipoMensalidade === 'com';
                $valorCentavos = null;
                if ($mensalidadeAtiva) {
                    $valorMensalRecebido = $_POST['valor_mensal'] ?? null;
                    if (!is_string($valorMensalRecebido)
                        || trim($valorMensalRecebido) === '') {
                        throw new RuntimeException('Informe o valor mensal do anuncio.');
                    }
                    $valorInformado = trim($valorMensalRecebido);
                    $valorCentavos = PrecificacaoReservaService::decimalParaCentavos(
                        str_replace(',', '.', $valorInformado)
                    );
                    if ($valorCentavos < 100) {
                        throw new RuntimeException('Informe um valor mensal de pelo menos R$ 1,00.');
                    }
                }

                // A configuracao ocorre antes da curta transacao de status. Apenas o fluxo com
                // mensalidade ativa sincroniza com o Asaas e pode manter a aprovacao pendente.
                (new MensalidadeAnuncioService())->configurar(
                    $chacaraId,
                    $mensalidadeAtiva,
                    $valorCentavos,
                    (int) Auth::user()['id']
                );
            }
            $atualizado = (new Chacara())->atualizarStatusAdministrativo(
                $chacaraId,
                $status,
                $motivo,
                (int) Auth::user()['id']
            );
            flash($atualizado ? 'success' : 'error', $atualizado
                ? ($status === 'aprovada'
                    ? ($mensalidadeAtiva
                        ? 'Imovel aprovado. A publicacao aguardara a confirmacao da mensalidade.'
                        : 'Imovel aprovado sem mensalidade e publicado imediatamente.')
                    : 'Status do imovel atualizado.')
                : 'Transicao de status invalida ou conta do proprietario bloqueada.');
        } catch (Throwable $exception) {
            flash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Nao foi possivel atualizar o status do imovel.');
        }
        $this->redirect('/admin/chacaras/' . $chacaraId);
    }

    public function usuarios(): void
    {
        Auth::requireRole('admin');

        $busca = trim((string) ($_GET['busca'] ?? ''));
        $model = new User();

        $this->view('admin/usuarios/index', [
            'title' => 'Usuarios',
            'panelRole' => 'admin',
            'busca' => $busca,
            'usuarios' => $this->corrigirCodificacaoLista(
                $model->listarClientesAdministrativo($busca)
            ),
        ], 'panel');
    }

    public function usuarioDetalhes(string $id): void
    {
        Auth::requireRole('admin');

        $usuarioId = $this->validarId($id);
        $model = new User();
        $usuario = $model->buscarClienteAdministrativo($usuarioId);

        if ($usuario === null) {
            $this->notFound();
        }

        $this->view('admin/usuarios/show', [
            'title' => 'Detalhes do usuario',
            'panelRole' => 'admin',
            'usuario' => $this->corrigirCodificacao($usuario),
            'reservas' => $this->corrigirCodificacaoLista(
                $model->listarReservasDoClienteAdministrativo($usuarioId)
            ),
        ], 'panel');
    }

    public function atualizarStatusUsuario(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();

        $usuarioId = $this->validarId($id);
        $status = (string) ($_POST['status'] ?? '');

        if ((int) Auth::user()['id'] === $usuarioId) {
            flash('error', 'Voce nao pode bloquear ou alterar o status da sua propria conta.');
            $this->redirect('/admin/usuarios');
        }

        if (!in_array($status, ['ativo', 'bloqueado'], true)) {
            flash('error', 'Status de usuario invalido.');
            $this->redirect('/admin/usuarios');
        }

        try {
            $atualizado = (new User())->atualizarStatusClienteAdministrativo($usuarioId, $status);
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar o status do usuario.');
            $this->redirect('/admin/usuarios/' . $usuarioId);
        }

        flash($atualizado ? 'success' : 'error', $atualizado
            ? 'Status do usuario atualizado com sucesso.'
            : 'Usuario nao encontrado.');
        $this->redirect('/admin/usuarios/' . $usuarioId);
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
            if (is_string($valor) && str_contains($valor, 'ÃƒÆ’')) {
                $corrigido = mb_convert_encoding($valor, 'Windows-1252', 'UTF-8');
                if (mb_check_encoding($corrigido, 'UTF-8')) {
                    $dados[$campo] = $corrigido;
                }
            }
        }

        return $dados;
    }

    private function dadosAdministrador(bool $exigirSenha): array
    {
        return [
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'telefone' => trim((string) ($_POST['telefone'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'senha' => (string) ($_POST['senha'] ?? ''),
            'senha_confirmacao' => (string) ($_POST['senha_confirmacao'] ?? ''),
            'status' => in_array(($_POST['status'] ?? 'ativo'), ['ativo', 'bloqueado'], true)
                ? (string) $_POST['status']
                : 'ativo',
        ];
    }

    private function validarAdministrador(array $data, bool $exigirSenha): ?string
    {
        if (mb_strlen($data['nome']) < 2) {
            return 'Informe o nome do administrador.';
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Informe um e-mail valido.';
        }

        if ($exigirSenha || $data['senha'] !== '' || $data['senha_confirmacao'] !== '') {
            if (strlen($data['senha']) < 6) {
                return 'A senha deve ter ao menos 6 caracteres.';
            }

            if ($data['senha'] !== $data['senha_confirmacao']) {
                return 'A confirmacao da senha nao confere.';
            }
        }

        return null;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Registro nao encontrado']);
        exit;
    }
}
