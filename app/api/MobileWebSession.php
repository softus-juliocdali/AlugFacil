<?php
declare(strict_types=1);
namespace App\Api;

use App\Core\Database;

/** Short-lived, single-use POST exchange. Access/refresh tokens never enter a URL or WebView. */
final class MobileWebSession
{
    public static function destination(string $path, string $role): bool
    {
        if (preg_match('~^/(cliente/historico|reserva/criar/[1-9][0-9]*)$~D', $path)) return true;
        return $role === 'proprietario' && in_array($path, [
            '/proprietario/dashboard','/proprietario/chacaras','/proprietario/chacaras/criar',
            '/proprietario/disponibilidade','/proprietario/faturamento','/proprietario/mensalidades',
            '/proprietario/recebimentos','/proprietario/dados-cadastrais',
        ], true);
    }

    public static function directory(): string
    {
        $dir = APP_ROOT . '/storage/cache/mobile-bridge';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Storage unavailable');
        return $dir;
    }

    public function issue(string $method): array
    {
        if ($method !== 'POST') throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Use POST.');
        $user = ApiAuth::user();
        $raw = file_get_contents('php://input', false, null, 0, 1025);
        $input = json_decode($raw ?: '', true);
        if (strlen($raw ?: '') > 1024 || !is_array($input) || array_keys($input) !== ['path'] || !is_string($input['path']) || !self::destination($input['path'], $user['tipo_usuario'])) {
            throw new ApiException(422, 'INVALID_DESTINATION', 'Destino indisponível para esta conta.');
        }
        $q = Database::getConnection()->prepare('SELECT session_id FROM mobile_access_tokens WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>clock_timestamp()');
        $q->execute(['hash'=>hash('sha256', ApiAuth::bearer())]);
        $session = $q->fetchColumn();
        if (!$session) throw MobileSessions::invalid();
        // Remove only expired exchange records in this private runtime directory.
        foreach (array_slice(glob(self::directory().'/*.json') ?: [],0,100) as $expired) {
            if (preg_match('/^[a-f0-9]{64}\.json$/D',basename($expired)) && filemtime($expired)<time()-120) @unlink($expired);
        }
        $ticket = bin2hex(random_bytes(32));
        $file = self::directory().'/'.hash('sha256',$ticket).'.json';
        $record = json_encode(['session'=>$session,'user'=>$user['id'],'role'=>$user['tipo_usuario'],'path'=>$input['path'],'expires'=>time()+60], JSON_THROW_ON_ERROR);
        $handle = fopen($file, 'x');
        if (!$handle) throw new \RuntimeException('Storage unavailable');
        chmod($file,0600);
        try { if (fwrite($handle,$record)!==strlen($record)) throw new \RuntimeException('Storage unavailable'); } finally { fclose($handle); }
        return [['ticket'=>$ticket,'expires_in'=>60],[]];
    }

    public static function consume(string $ticket): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$ticket)) throw MobileSessions::invalid();
        $file = self::directory().'/'.hash('sha256',$ticket).'.json';
        $handle = @fopen($file,'r+');
        if (!$handle) throw MobileSessions::invalid();
        try {
            if (!flock($handle,LOCK_EX)) throw MobileSessions::invalid();
            $record = json_decode(stream_get_contents($handle),true);
            ftruncate($handle,0); // Other processes opening before unlink still cannot replay it.
            if (!is_array($record) || ($record['expires']??0)<time()) throw MobileSessions::invalid();
            $user = self::linkedUser($record['session'],(int)$record['user']);
            if (!$user || $record['role']!==$user['tipo_usuario'] || !self::destination($record['path'],$user['tipo_usuario'])) throw MobileSessions::invalid();
            return $record+['account'=>$user];
        } finally { flock($handle,LOCK_UN);fclose($handle);@unlink($file); }
    }

    public static function linkedUser(string $session, int $userId): ?array
    {
        $q=Database::getConnection()->prepare("SELECT u.*,s.credential_stamp FROM mobile_sessions s JOIN usuarios u ON u.id=s.usuario_id WHERE s.id=:s AND u.id=:u AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() AND u.status='ativo' AND u.tipo_usuario IN ('cliente','proprietario')");
        $q->execute(['s'=>$session,'u'=>$userId]);$user=$q->fetch();
        return $user && hash_equals($user['credential_stamp'],hash('sha256',$user['senha_hash'])) ? $user : null;
    }
}
