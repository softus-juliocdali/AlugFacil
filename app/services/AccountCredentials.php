<?php
declare(strict_types=1);
namespace App\Services;

/** Shared with Web; mobile restrictions live outside this class. */
final class AccountCredentials
{
    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function registrationError(array $data): ?string
    {
        if (mb_strlen($data['nome']) < 2) return 'Informe seu nome completo.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return 'Informe um e-mail válido.';
        if (strlen($data['senha']) < 6) return 'A senha deve ter ao menos 6 caracteres.';
        if ($data['senha'] !== $data['senha_confirmacao']) return 'A confirmação da senha não confere.';
        return null;
    }

    public static function verify(?array $user, string $password): bool
    {
        // A fixed, valid bcrypt hash equalizes the missing-account password work.
        $hash = $user['senha_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $hash);
        return $user !== null && $valid;
    }
}
