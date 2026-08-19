<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\User;
use DateTimeImmutable;
use Throwable;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::redirectPath());
        }
        $this->view('auth/login', ['title' => 'Entrar']);
    }

    public function login(): void
    {
        verify_csrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['senha'] ?? '');
        set_old(['email' => $email], 'auth');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            flash('error', 'Informe e-mail e senha válidos.');
            $this->redirect('/login');
        }

        $user = (new User())->findByEmail($email);
        if ($user === null || !password_verify($password, $user['senha_hash'])) {
            flash('error', 'E-mail ou senha incorretos.');
            $this->redirect('/login');
        }
        if ($user['status'] !== 'ativo') {
            flash('error', 'Este usuário está bloqueado. Entre em contato com o suporte.');
            $this->redirect('/login');
        }

        clear_old();
        Auth::login($user);
        flash('success', 'Login realizado com sucesso.');
        $this->redirect(Auth::redirectPath());
    }

    public function showClientRegistration(): void
    {
        $this->view('auth/register', ['title' => 'Criar conta']);
    }

    public function registerClient(): void
    {
        $this->register('cliente');
    }

    public function showOwnerRegistration(): void
    {
        $this->view('auth/register-owner', ['title' => 'Cadastrar proprietário']);
    }

    public function registerOwner(): void
    {
        $this->register('proprietario');
    }

    private function register(string $role): void
    {
        verify_csrf();
        $data = $this->registrationData();
        set_old([
            'nome' => $data['nome'],
            'telefone' => $data['telefone'],
            'email' => $data['email'],
        ], 'auth');
        $error = $this->validateRegistration($data);
        $returnPath = $role === 'proprietario' ? '/cadastro-proprietario' : '/cadastro';

        if ($error !== null) {
            flash('error', $error);
            $this->redirect($returnPath);
        }

        $model = new User();
        if ($model->emailExists($data['email'])) {
            flash('error', 'Já existe uma conta com este e-mail.');
            $this->redirect($returnPath);
        }

        try {
            $role === 'proprietario'
                ? $model->createOwner($data)
                : $model->createClient($data);
        } catch (Throwable) {
            flash('error', 'Não foi possível concluir o cadastro. Tente novamente.');
            $this->redirect($returnPath);
        }

        clear_old();
        flash('success', $role === 'proprietario'
            ? 'Cadastro recebido! Sua conta de proprietário foi criada.'
            : 'Cadastro realizado! Agora você já pode entrar.');
        $this->redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $this->view('auth/forgot-password', ['title' => 'Esqueceu a senha']);
    }

    public function forgotPassword(): void
    {
        verify_csrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $model = new User();
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? $model->findByEmail($email) : null;

        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
            $model->createPasswordReset((int) $user['id'], $token, $expiresAt);

            if (config('app_env') === 'development') {
                $_SESSION['_reset_link'] = url('/redefinir-senha?token=' . urlencode($token));
            }
        }

        flash('success', 'Se o e-mail estiver cadastrado, você receberá as instruções para redefinir a senha.');
        $this->redirect('/esqueceu-senha');
    }

    public function resetPasswordForm(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $reset = $token !== '' ? (new User())->findValidPasswordReset($token) : null;
        if ($reset === null) {
            flash('error', 'O link de redefinição é inválido ou expirou.');
            $this->redirect('/esqueceu-senha');
        }
        $this->view('auth/reset-password', ['title' => 'Criar nova senha', 'token' => $token]);
    }

    public function resetPassword(): void
    {
        verify_csrf();
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['senha'] ?? '');
        $confirmation = (string) ($_POST['senha_confirmacao'] ?? '');
        $model = new User();
        $reset = $token !== '' ? $model->findValidPasswordReset($token) : null;

        if ($reset === null) {
            flash('error', 'O link de redefinição é inválido ou expirou.');
            $this->redirect('/esqueceu-senha');
        }
        if (strlen($password) < 6 || $password !== $confirmation) {
            flash('error', 'A senha deve ter ao menos 6 caracteres e a confirmação deve ser igual.');
            $this->redirect('/redefinir-senha?token=' . urlencode($token));
        }

        $model->resetPassword((int) $reset['id'], (int) $reset['usuario_id'], $password);
        flash('success', 'Senha redefinida com sucesso. Faça seu login.');
        $this->redirect('/login');
    }

    public function logout(): void
    {
        Auth::logout();
        flash('success', 'Você saiu da sua conta.');
        $this->redirect('/login');
    }

    private function registrationData(): array
    {
        return [
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'telefone' => trim((string) ($_POST['telefone'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'senha' => (string) ($_POST['senha'] ?? ''),
            'senha_confirmacao' => (string) ($_POST['senha_confirmacao'] ?? ''),
        ];
    }

    private function validateRegistration(array $data): ?string
    {
        if (mb_strlen($data['nome']) < 2) {
            return 'Informe seu nome completo.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Informe um e-mail válido.';
        }
        if (strlen($data['senha']) < 6) {
            return 'A senha deve ter ao menos 6 caracteres.';
        }
        if ($data['senha'] !== $data['senha_confirmacao']) {
            return 'A confirmação da senha não confere.';
        }
        return null;
    }
}
