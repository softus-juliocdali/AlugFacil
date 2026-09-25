<?php
declare(strict_types=1);
namespace App\Api;

use App\Core\Database;
use App\Models\User;
use App\Services\AccountCredentials;
use PDOException;

final class MobileAuthApi
{
    public static function handles(string $path): bool
    {
        return in_array($path, ['/api/v1/me', '/api/v1/auth/login', '/api/v1/auth/cadastro', '/api/v1/auth/refresh', '/api/v1/auth/logout'], true);
    }

    public function dispatch(string $method, string $path, array $query): array
    {
        if ($method !== ($path === '/api/v1/me' ? 'GET' : 'POST')) throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metodo nao permitido.');
        PublicQuery::keys($query, []);
        if ($path === '/api/v1/me') return [ApiAuth::user(), []];
        $action = basename($path);
        $keys = match ($action) {
            'cadastro' => ['nome', 'telefone', 'email', 'senha', 'senha_confirmacao', 'device_name'],
            'login' => ['email', 'senha', 'device_name'],
            default => ['refresh_token'],
        };
        $input = self::body($keys);
        if (isset(MobileAuthPolicy::RATE_LIMITS[$action])) $this->rateLimit($action, $input);
        $sessions = new MobileSessions();
        if ($action === 'refresh' || $action === 'logout') {
            $token = $input['refresh_token'] ?? '';
            if ($action === 'logout') { $sessions->logout($token); return [['logged_out' => true], []]; }
            $pair = $sessions->refresh($token);
        } else {
            $input['email'] = AccountCredentials::email($input['email'] ?? '');
            $input['senha'] = $input['senha'] ?? '';
            $model = new User();
            if ($action === 'cadastro') {
                $input['nome'] = trim($input['nome'] ?? '');
                $input['telefone'] = trim($input['telefone'] ?? '');
                $input['senha_confirmacao'] = $input['senha_confirmacao'] ?? '';
                $error = AccountCredentials::registrationError($input);
                if ($error) throw new ApiException(422, 'VALIDATION_ERROR', $error);
                if ($model->emailExists($input['email'])) throw new ApiException(409, 'EMAIL_EXISTS', 'Já existe uma conta com este e-mail.', ['email' => 'E-mail já cadastrado.']);
                try { $id = $model->createClient($input); }
                catch (PDOException $e) {
                    if ($e->getCode() === '23505') throw new ApiException(409, 'EMAIL_EXISTS', 'Já existe uma conta com este e-mail.');
                    throw $e;
                }
                $user = $model->findById($id);
            } else {
                $user = $model->findByEmail($input['email']);
                if (!AccountCredentials::verify($user, $input['senha'])) throw new ApiException(401, 'INVALID_CREDENTIALS', 'E-mail ou senha incorretos.');
                if ($user['status'] !== 'ativo' || !in_array($user['tipo_usuario'], ['cliente', 'proprietario'], true)) throw new ApiException(403, 'ACCOUNT_UNAVAILABLE', 'Esta conta não pode acessar o aplicativo.');
            }
            $pair = $sessions->create($user, $input['device_name'] ?? null);
        }
        unset($pair['_refresh_id']);
        return [$pair, []];
    }

    private static function body(array $keys): array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new ApiException(422, 'VALIDATION_ERROR', 'Envie um objeto JSON.');
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        $object = json_decode($raw ?: '', false);
        if (strlen($raw ?: '') > 16384 || !$object instanceof \stdClass) throw new ApiException(422, 'VALIDATION_ERROR', 'JSON inválido ou excede o limite.');
        $data = (array) $object;
        foreach ($data as $key => $value) {
            $limit = match ($key) { 'nome' => 150, 'telefone' => 30, 'email' => 180, 'device_name' => 120, 'refresh_token' => 64, default => 1024 };
            if (!in_array($key, $keys, true) || !is_string($value) || mb_strlen($value) > $limit || str_contains($value, "\0")) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Confira os campos informados.', [$key => 'Campo inválido.']);
            }
        }
        return $data;
    }

    private function rateLimit(string $action, array $input): void
    {
        [$limit, $seconds] = MobileAuthPolicy::RATE_LIMITS[$action];
        $identities = [($action . '|ip|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => $limit];
        if ($action === 'login') $identities['login|email|' . AccountCredentials::email($input['email'] ?? '')] = 10;
        $db = Database::getConnection();
        // Expired buckets carry no identity data (only hashes), and are bounded by TTL.
        $db->exec('DELETE FROM mobile_auth_rate_limits WHERE expires_at < clock_timestamp()');
        foreach ($identities as $identity => $maximum) {
            $q = $db->prepare('INSERT INTO mobile_auth_rate_limits(bucket_hash,attempts,expires_at) VALUES(:key,1,clock_timestamp()+make_interval(secs => :ttl)) ON CONFLICT(bucket_hash) DO UPDATE SET attempts=mobile_auth_rate_limits.attempts+1 RETURNING attempts');
            $q->execute(['key' => hash('sha256', $identity), 'ttl' => $seconds]);
            if ((int) $q->fetchColumn() > $maximum) throw new ApiException(429, 'RATE_LIMITED', 'Muitas tentativas. Aguarde antes de tentar novamente.');
        }
    }
}
