<?php
declare(strict_types=1);
namespace App\Api;

use App\Core\Database;
use PDO;
use Throwable;

final class MobileSessions
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getConnection(); }

    private function row(string $sql, array $params): ?array
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch() ?: null;
    }

    private function execute(string $sql, array $params): void
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
    }

    public static function dto(array $user): array
    {
        return ['id' => (int) $user['id'], 'nome' => $user['nome'], 'email' => $user['email'],
            'telefone' => $user['telefone'], 'tipo_usuario' => 'cliente'];
    }

    public function create(array $user, ?string $device): array
    {
        $this->db->beginTransaction();
        try {
            // Serialize against password/status updates before issuing a new session.
            $current = $this->row('SELECT * FROM usuarios WHERE id=:id FOR SHARE', ['id' => $user['id']]);
            if (!$current || $current['status'] !== 'ativo' || $current['tipo_usuario'] !== 'cliente'
                || !hash_equals($user['senha_hash'], $current['senha_hash'])) throw self::invalid();
            $id = bin2hex(random_bytes(16));
            $this->execute('INSERT INTO mobile_sessions(id,usuario_id,credential_stamp,device_name,expires_at) VALUES(:id,:user,:stamp,:device,clock_timestamp() + make_interval(secs => :ttl))',
                ['id' => $id, 'user' => $user['id'], 'stamp' => hash('sha256', $user['senha_hash']), 'device' => $device, 'ttl' => MobileAuthPolicy::SESSION_SECONDS]);
            $pair = $this->pair($id);
            $this->db->commit();
            return $pair + ['user' => self::dto($current)];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function pair(string $id): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $a = $this->row('INSERT INTO mobile_access_tokens(session_id,token_hash,expires_at) SELECT id,:hash,LEAST(expires_at,clock_timestamp()+make_interval(secs => :ttl)) FROM mobile_sessions WHERE id=:id RETURNING expires_at',
            ['id' => $id, 'hash' => hash('sha256', $access), 'ttl' => MobileAuthPolicy::ACCESS_SECONDS]);
        $r = $this->row('INSERT INTO mobile_refresh_tokens(session_id,token_hash,expires_at) SELECT id,:hash,LEAST(expires_at,clock_timestamp()+make_interval(secs => :ttl)) FROM mobile_sessions WHERE id=:id RETURNING id, expires_at',
            ['id' => $id, 'hash' => hash('sha256', $refresh), 'ttl' => MobileAuthPolicy::REFRESH_SECONDS]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer',
            'expires_in' => max(0, strtotime($a['expires_at']) - time()), 'access_expires_at' => $a['expires_at'],
            'refresh_expires_at' => $r['expires_at'], '_refresh_id' => (int) $r['id']];
    }

    public static function invalid(): ApiException { return new ApiException(401, 'UNAUTHENTICATED', 'Entre novamente para continuar.'); }

    private function revoke(string $id, string $reason): void
    {
        $this->execute('UPDATE mobile_sessions SET revoked_at=COALESCE(revoked_at,clock_timestamp()),revocation_reason=COALESCE(revocation_reason,:reason) WHERE id=:id', ['id' => $id, 'reason' => $reason]);
        foreach (['mobile_access_tokens', 'mobile_refresh_tokens'] as $table) {
            $this->execute("UPDATE $table SET revoked_at=COALESCE(revoked_at,clock_timestamp()) WHERE session_id=:id", ['id' => $id]);
        }
    }

    private function validateSession(array $s): array
    {
        $user = $this->row('SELECT * FROM usuarios WHERE id=:id FOR SHARE', ['id' => $s['usuario_id']]);
        $reason = $s['revoked_at'] !== null ? 'revoked' : (strtotime($s['expires_at']) <= time() ? 'absolute_expiry' : null);
        if (!$user || $user['status'] !== 'ativo' || $user['tipo_usuario'] !== 'cliente') $reason = 'account_unavailable';
        elseif (!hash_equals($s['credential_stamp'], hash('sha256', $user['senha_hash']))) $reason = 'password_changed';
        if ($reason !== null) {
            $this->revoke($s['id'], $reason);
            $this->db->commit(); // Revocation must survive the error response.
            throw self::invalid();
        }
        return $user;
    }

    private function lock(string $raw, string $table): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $raw)) throw self::invalid();
        $token = $this->row("SELECT * FROM $table WHERE token_hash=:hash", ['hash' => hash('sha256', $raw)]);
        if (!$token) throw self::invalid();
        $session = $this->row('SELECT * FROM mobile_sessions WHERE id=:id FOR UPDATE', ['id' => $token['session_id']]);
        if (!$session) throw self::invalid();
        // Re-read after the family lock: another refresh may have just consumed this token.
        $token = $this->row("SELECT * FROM $table WHERE id=:id", ['id' => $token['id']]);
        return [$session, $token];
    }

    public function refresh(string $raw): array
    {
        $this->db->beginTransaction();
        try {
            [$s, $token] = $this->lock($raw, 'mobile_refresh_tokens');
            $user = $this->validateSession($s);
            if ($token['used_at'] !== null || $token['revoked_at'] !== null || strtotime($token['expires_at']) <= time()) {
                $this->revoke($s['id'], $token['used_at'] !== null ? 'refresh_replay' : 'refresh_expired');
                $this->db->commit();
                throw self::invalid();
            }
            $this->execute('UPDATE mobile_access_tokens SET revoked_at=clock_timestamp() WHERE session_id=:id AND revoked_at IS NULL', ['id' => $s['id']]);
            $pair = $this->pair($s['id']);
            $this->execute('UPDATE mobile_refresh_tokens SET used_at=clock_timestamp(),revoked_at=clock_timestamp(),successor_id=:next WHERE id=:id', ['id' => $token['id'], 'next' => $pair['_refresh_id']]);
            $this->execute('UPDATE mobile_sessions SET last_used_at=clock_timestamp() WHERE id=:id', ['id' => $s['id']]);
            $this->db->commit();
            return $pair + ['user' => self::dto($user)];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function authenticate(string $raw): array
    {
        $this->db->beginTransaction();
        try {
            [$s, $token] = $this->lock($raw, 'mobile_access_tokens');
            $user = $this->validateSession($s);
            if ($token['revoked_at'] !== null || strtotime($token['expires_at']) <= time()) throw self::invalid();
            $this->execute('UPDATE mobile_sessions SET last_used_at=clock_timestamp() WHERE id=:id', ['id' => $s['id']]);
            $this->db->commit();
            return self::dto($user);
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** Refresh credential allows logout even after access expiry; no rotation required. */
    public function logout(string $raw): void
    {
        $this->db->beginTransaction();
        try {
            [$s] = $this->lock($raw, 'mobile_refresh_tokens');
            $this->revoke($s['id'], 'logout');
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
