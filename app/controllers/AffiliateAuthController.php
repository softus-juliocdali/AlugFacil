<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AffiliateAuth;
use App\Core\Controller;
use App\Models\Affiliate;

final class AffiliateAuthController extends Controller
{
    public function showLogin(): void
    {
        if (AffiliateAuth::check()) {
            $this->redirect('/afiliado');
        }
        $this->view('afiliado/login', ['title' => 'Acesso do afiliado']);
    }

    public function login(): void
    {
        verify_csrf('/afiliado/login');
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['senha'] ?? '');
        set_old(['email' => $email]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            flash('error', 'Informe e-mail e senha válidos.');
            $this->redirect('/afiliado/login');
        }
        $affiliate = (new Affiliate())->findByEmail($email);
        if (!Affiliate::credentialsMatch($affiliate, $password)) {
            flash('error', 'E-mail ou senha inválidos.');
            $this->redirect('/afiliado/login');
        }
        if (($affiliate['status'] ?? null) !== 'ativo') {
            flash('error', 'Seu acesso de afiliado está bloqueado.');
            $this->redirect('/afiliado/login');
        }
        clear_old();
        AffiliateAuth::login($affiliate);
        $this->redirect('/afiliado');
    }

    public function logout(): void
    {
        verify_csrf('/afiliado/login');
        AffiliateAuth::logout();
        flash('success', 'Sessão de afiliado encerrada.');
        $this->redirect('/afiliado/login');
    }
}
