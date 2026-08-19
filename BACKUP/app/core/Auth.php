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
        unset($_SESSION['user'], $_SESSION['_old']);
        session_regenerate_id(true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
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
