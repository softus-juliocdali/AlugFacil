<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_auth_affiliate_id'], $_SESSION['_mobile_session_id'], $_SESSION['_mobile_role']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'email' => $user['email'],
            'role' => $user['tipo_usuario'] ?? $user['role'],
            'status' => $user['status'],
        ];
    }

    public static function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['_old'], $_SESSION['_old_next'], $_SESSION['_auth_affiliate_id'], $_SESSION['_mobile_session_id'], $_SESSION['_mobile_role']);
        session_regenerate_id(true);
    }

    public static function requireLogin(): void
    {
        if (isset($_SESSION['_mobile_session_id'])) {
            $linked = \App\Api\MobileWebSession::linkedUser((string) $_SESSION['_mobile_session_id'], (int) (self::user()['id'] ?? 0));
            if (!$linked || $linked['tipo_usuario'] !== ($_SESSION['_mobile_role'] ?? null)) self::logout();
        }
        if (!self::check()) {
            self::rememberReturnPath();
            flash('error', 'Faça login para acessar esta área.');
            header('Location: ' . url('/login'));
            exit;
        }

        $statement = Database::getConnection()->prepare(
            'SELECT nome, email, tipo_usuario, status FROM usuarios WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => self::user()['id']]);
        $user = $statement->fetch();

        if (!$user || $user['status'] !== 'ativo') {
            self::logout();
            flash('error', 'Este usuário está bloqueado ou não está mais disponível.');
            header('Location: ' . url('/login'));
            exit;
        }

        if (isset($_SESSION['_auth_affiliate_id'])) {
            $principal=(new \App\Services\AffiliateIdentityService())->principal((int)$_SESSION['_auth_affiliate_id']);
            if (!$principal || (int)$principal['id']!==(int)self::user()['id']) {
                self::logout();
                header('Location: '.url('/afiliado/login'));
                exit;
            }
        }

        $_SESSION['user'] = array_merge($_SESSION['user'], [
            'nome' => $user['nome'],
            'email' => $user['email'],
            'role' => $user['tipo_usuario'],
            'status' => $user['status'],
        ]);
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        // A linked affiliate login grants only guest capabilities, never administrative access.
        if (isset($_SESSION['_auth_affiliate_id'])) {
            self::logout();
            flash('error','Entre com sua conta principal para acessar esta funcao.');
            header('Location: '.url('/login'));
            exit;
        }
        $role = self::user()['role'] ?? null;

        if (!in_array($role, $roles, true)) {
            flash('error', 'Você não tem permissão para acessar esta área.');
            header('Location: ' . url(self::redirectPath($role)));
            exit;
        }
    }

    public static function requireProprietarioAutenticado(): array
    {
        self::requireRole('proprietario');

        $statement = Database::getConnection()->prepare(
            <<<'SQL'
                SELECT p.*, u.status AS usuario_status
                FROM proprietarios p
                INNER JOIN usuarios u ON u.id = p.usuario_id
                WHERE p.usuario_id = :usuario_id
                LIMIT 1
                SQL
        );
        $statement->execute(['usuario_id' => (int) self::user()['id']]);
        $proprietario = $statement->fetch();

        if (!$proprietario) {
            self::logout();
            flash('error', 'Nao foi possivel localizar seu cadastro de proprietario.');
            header('Location: ' . url('/login'));
            exit;
        }

        return $proprietario;
    }

    public static function requireGuest(): void
    {
        if (!self::check() && AffiliateAuth::check()) {
            $a=AffiliateAuth::requireLogin();
            $principal=(new \App\Services\AffiliateIdentityService())->principal((int)$a['id']);
            if (!$principal) {
                self::rememberReturnPath();
                header('Location: '.url('/afiliado/identidade'));
                exit;
            }
            self::login($principal);
            $_SESSION['_auth_affiliate_id']=(int)$a['id'];
        }
        self::requireLogin();
        if (!in_array('reserva.criar',(new \App\Services\ReservationAuthorization())->capabilities((int)self::user()['id']),true)) {
            http_response_code(403);exit('Identidade sem capacidade de hospede.');
        }
    }

    public static function rememberReturnPath(): void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $path=parse_url($uri,PHP_URL_PATH);
        if(!is_string($path)||!preg_match('~^/(reserva/(criar|confirmacao)/[1-9][0-9]*|cliente/(historico|reserva/[1-9][0-9]*)|afiliado/identidade)$~D',$path))return;
        parse_str((string)parse_url($uri,PHP_URL_QUERY),$params);$dates=[];
        foreach(['data_inicio','data_fim'] as $key)if(isset($params[$key])&&is_string($params[$key])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$params[$key]))$dates[$key]=$params[$key];
        $_SESSION['_auth_return']=['path'=>$path.($dates?'?'.http_build_query($dates):''),'expires'=>time()+1800];
    }

    public static function consumeReturnPath(): string
    {
        $return=$_SESSION['_auth_return']??null;unset($_SESSION['_auth_return']);
        return is_array($return)&&($return['expires']??0)>time() ? $return['path'] : self::redirectPath();
    }

    public static function requireProprietarioOperacional(bool $json = false): array
    {
        return self::requireProprietarioAutenticado();
    }

    public static function redirectPath(?string $role = null): string
    {
        return match ($role ?? (self::user()['role'] ?? null)) {
            'cliente' => '/cliente/historico',
            'proprietario' => '/proprietario/dashboard',
            'admin' => '/admin/dashboard',
            default => '/login',
        };
    }
}
