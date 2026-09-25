<?php

declare(strict_types=1);

namespace App\Core;

final class AffiliateAuth
{
    public static function check(): bool
    {
        return isset($_SESSION['affiliate']) && is_array($_SESSION['affiliate']);
    }

    public static function user(): ?array
    {
        return self::check() ? $_SESSION['affiliate'] : null;
    }

    public static function login(array $affiliate): void
    {
        session_regenerate_id(true);
        $_SESSION['affiliate'] = [
            'id' => (int) $affiliate['id'],
            'codigo' => (string) $affiliate['codigo'],
            'nome' => (string) $affiliate['nome'],
            'email' => (string) $affiliate['email'],
            'status' => (string) $affiliate['status'],
        ];
    }

    public static function logout(): void
    {
        if(isset($_SESSION['_auth_affiliate_id'])) Auth::logout();
        unset($_SESSION['affiliate']);
        session_regenerate_id(true);
    }

    public static function requireLogin(): array
    {
        if (!self::check()) {
            flash('error', 'Faça login como afiliado para acessar esta área.');
            header('Location: ' . url('/afiliado/login'));
            exit;
        }

        $statement = Database::getConnection()->prepare(
            'SELECT id, codigo, nome, email, status FROM afiliados WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) self::user()['id']]);
        $affiliate = $statement->fetch();

        if (!$affiliate || $affiliate['status'] !== 'ativo') {
            self::logout();
            flash('error', 'Este afiliado está bloqueado ou não está mais disponível.');
            header('Location: ' . url('/afiliado/login'));
            exit;
        }

        $_SESSION['affiliate'] = [
            'id' => (int) $affiliate['id'],
            'codigo' => (string) $affiliate['codigo'],
            'nome' => (string) $affiliate['nome'],
            'email' => (string) $affiliate['email'],
            'status' => (string) $affiliate['status'],
        ];

        return $_SESSION['affiliate'];
    }
}
