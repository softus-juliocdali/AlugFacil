<?php
declare(strict_types=1);
require __DIR__ . '/mobile_auth_environment.php';
use App\Api\MobileSessions;
use App\Api\ApiException;
use App\Models\User;

function rejected(callable $call, string $label): void {
    try { $call(); } catch (ApiException $e) { apiCheck($e->status === 401, $label); return; }
    throw new RuntimeException('FAIL: ' . $label);
}
$schema = 'mobile_auth_test_' . bin2hex(random_bytes(8));
$server = null;
try {
    $db->exec('CREATE SCHEMA ' . $schema);
    $db->exec('SET search_path TO ' . $schema . ', pg_catalog');
    $db->exec('CREATE TABLE usuarios (LIKE public.usuarios INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
    // Independent sequence; never consume the real usuarios sequence.
    $db->exec('CREATE SEQUENCE test_user_id; ALTER TABLE usuarios ALTER COLUMN id SET DEFAULT nextval(\'test_user_id\'); ALTER TABLE usuarios ADD PRIMARY KEY(id)');
    $db->exec(file_get_contents(APP_ROOT . '/database/20260921_mobile_auth.sql'));
    $model = new User();
    $id = $model->createClient(['nome' => 'Cliente Teste', 'email' => 'auth@teste.invalid', 'telefone' => '', 'senha' => 'teste-seguro-local']);
    $user = $model->findById($id);
    $sessions = new MobileSessions($db);
    $a = $sessions->create($user, 'Teste local');
    apiCheck($sessions->authenticate($a['access_token'])['id'] === $id, 'Access resolves current client');
    apiCheck(!isset($a['user']['senha_hash']), 'DTO excludes password');
    apiCheck($db->query('SELECT token_hash FROM mobile_access_tokens LIMIT 1')->fetchColumn() !== $a['access_token'], 'Only token hash stored');
    $b = $sessions->refresh($a['refresh_token']);
    apiCheck($a['refresh_token'] !== $b['refresh_token'], 'Refresh rotation');
    rejected(fn() => $sessions->authenticate($a['access_token']), 'Old access revoked by rotation');
    rejected(fn() => $sessions->refresh($a['refresh_token']), 'Refresh replay rejected');
    rejected(fn() => $sessions->authenticate($b['access_token']), 'Replay revokes successor access');
    rejected(fn() => $sessions->refresh($b['refresh_token']), 'Replay revokes successor refresh');
    $c = $sessions->create($user, null);
    $sessions->logout($c['refresh_token']);
    rejected(fn() => $sessions->authenticate($c['access_token']), 'Logout revokes access');
    rejected(fn() => $sessions->refresh($c['refresh_token']), 'Logout revokes refresh');
    $c = $sessions->create($user, null);
    $db->exec("UPDATE mobile_access_tokens SET expires_at=clock_timestamp()-interval '1 second'");
    rejected(fn() => $sessions->authenticate($c['access_token']), 'Access expiry');
    $c = $sessions->refresh($c['refresh_token']);
    apiCheck($sessions->authenticate($c['access_token'])['id'] === $id, 'Expired access may refresh');
    $db->exec("UPDATE mobile_sessions SET expires_at=clock_timestamp()-interval '1 second'");
    rejected(fn() => $sessions->refresh($c['refresh_token']), 'Absolute expiry');
    $c = $sessions->create($user, null);
    $db->exec("UPDATE mobile_refresh_tokens SET expires_at=clock_timestamp()-interval '1 second'");
    rejected(fn() => $sessions->refresh($c['refresh_token']), 'Refresh inactivity expiry');
    $c = $sessions->create($user, null);
    $db->exec("UPDATE usuarios SET status='bloqueado'");
    rejected(fn() => $sessions->authenticate($c['access_token']), 'Account status rechecked');
    $db->exec("UPDATE usuarios SET status='ativo'");
    $c = $sessions->create($user, null);
    $db->prepare('UPDATE usuarios SET senha_hash=:hash')->execute(['hash' => password_hash('nova-senha-local', PASSWORD_DEFAULT)]);
    rejected(fn() => $sessions->refresh($c['refresh_token']), 'Web password change invalidates mobile');
    apiCheck($db->query("SELECT COUNT(*) FROM mobile_sessions WHERE revocation_reason='password_changed'")->fetchColumn() > 0, 'Credential revocation persisted');
    $user = $model->findById($id);
    $c = $sessions->create($user, null);
    $workers = [];
    $start = microtime(true) + 1;
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/support/mobile_refresh_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT);
        fwrite($pipes[0], json_encode(['schema' => $schema, 'token' => $c['refresh_token'], 'start' => $start]));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    sort($statuses);
    apiCheck($statuses === ['200', '401'], 'Concurrent rotation permits only one success');
    $sid = $db->query("SELECT session_id FROM mobile_refresh_tokens WHERE token_hash='" . hash('sha256', $c['refresh_token']) . "'")->fetchColumn();
    $q = $db->prepare('SELECT revoked_at IS NOT NULL FROM mobile_sessions WHERE id=:id'); $q->execute(['id' => $sid]);
    apiCheck((bool) $q->fetchColumn(), 'Concurrent reuse revokes complete family');
    $server = new PublicApiTestServer(['PGOPTIONS' => '-c search_path=' . $schema . ',pg_catalog', 'APP_ENV' => 'test']);
    $post = static function (string $action, array $body, int $expected) use ($server): array {
        $r = $server->request('/api/v1/auth/' . $action, 'POST', ['Content-Type: application/json'], json_encode($body));
        apiCheck($r['status'] === $expected, 'Auth ' . $action . ' HTTP ' . $expected);
        apiCheck(!isset($r['headers']['set-cookie']) && !isset($r['headers']['location']), 'Mobile response has no Web cookie or redirect');
        return $r['json']['data'] ?? $r['json'];
    };
    $account = ['nome' => 'Cliente HTTP', 'email' => 'http@teste.invalid', 'telefone' => '', 'senha' => 'http-teste-local', 'senha_confirmacao' => 'http-teste-local'];
    $post('cadastro', $account + ['tipo_usuario' => 'admin'], 422);
    $post('cadastro', array_replace($account, ['senha_confirmacao' => 'errada']), 422);
    $h = $post('cadastro', $account, 201);
    $post('cadastro', array_replace($account, ['email' => ' HTTP@TESTE.INVALID ']), 409);
    $server->api('/api/v1/me', 401);
    $me = $server->api('/api/v1/me', 200, 'GET', ['Authorization: Bearer ' . $h['access_token']]);
    apiCheck(array_keys($me['json']['data']) === ['id', 'nome', 'email', 'telefone', 'tipo_usuario'], 'Me strict public allowlist');
    $wrong = $post('login', ['email' => $account['email'], 'senha' => 'errada'], 401);
    $missing = $post('login', ['email' => 'missing@teste.invalid', 'senha' => 'errada'], 401);
    apiCheck($wrong['error'] === $missing['error'], 'Missing and wrong password same error');
    $h = $post('login', ['email' => ' HTTP@TESTE.INVALID ', 'senha' => $account['senha']], 200);
    $h = $post('refresh', ['refresh_token' => $h['refresh_token']], 200);
    $post('logout', ['refresh_token' => $h['refresh_token']], 200);
    $server->api('/api/v1/me', 401, 'GET', ['Authorization: Bearer ' . $h['access_token']]);
    $db->exec("UPDATE usuarios SET tipo_usuario='proprietario' WHERE email='http@teste.invalid'");
    $post('login', ['email' => $account['email'], 'senha' => $account['senha']], 200);
    $db->exec("UPDATE usuarios SET tipo_usuario='cliente',status='bloqueado' WHERE email='http@teste.invalid'");
    $post('login', ['email' => $account['email'], 'senha' => $account['senha']], 403);
    $db->exec('DELETE FROM mobile_auth_rate_limits');
    for ($i = 0; $i < 10; $i++) $post('login', ['email' => 'missing@teste.invalid', 'senha' => 'errada'], 401);
    $post('login', ['email' => 'missing@teste.invalid', 'senha' => 'errada'], 429);
    apiCheck($server->sessionFileCount() === 0, 'No PHP session file created by mobile auth');
    // Web session and CSRF regression against the same isolated usuarios table.
    $db->exec("UPDATE usuarios SET status='ativo' WHERE email='http@teste.invalid'");
    $web = $server->request('/login');
    preg_match('/name="_token" value="([^"]+)"/', $web['body'], $csrf);
    $cookie = explode(';', $web['headers']['set-cookie'][0])[0];
    apiCheck($web['status'] === 200 && isset($csrf[1]), 'Web login and CSRF form');
    $bad = $server->request('/login', 'POST', ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['email' => $account['email'], 'senha' => $account['senha']]));
    apiCheck($bad['status'] === 302 && str_ends_with($bad['headers']['location'][0], '/login'), 'Web rejects missing CSRF');
    $web = $server->request('/login', 'POST', ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['_token' => $csrf[1], 'email' => $account['email'], 'senha' => $account['senha']]));
    apiCheck($web['status'] === 302, 'Web credentials login');
    $cookie = explode(';', $web['headers']['set-cookie'][0])[0];
    $profile = $server->request('/cliente/meus-dados', 'GET', ['Cookie: ' . $cookie]);
    apiCheck($profile['status'] === 200 && str_contains($profile['body'], $account['email']), 'Authenticated Web profile');
    $server->api('/api/v1/me', 401, 'GET', ['Cookie: ' . $cookie]);
    $db->exec('DELETE FROM mobile_auth_rate_limits');
    $h = $post('login', ['email' => $account['email'], 'senha' => $account['senha']], 200);
    $post('logout', ['refresh_token' => $h['refresh_token']], 200);
    apiCheck($server->request('/cliente/meus-dados', 'GET', ['Cookie: ' . $cookie])['status'] === 200, 'Mobile logout preserves Web session');
    $h = $post('login', ['email' => $account['email'], 'senha' => $account['senha']], 200);
    preg_match('/name="_token" value="([^"]+)"/', $profile['body'], $csrf);
    $changed = $server->request('/cliente/meus-dados', 'POST', ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['_token' => $csrf[1], 'nome' => $account['nome'], 'telefone' => '', 'email' => $account['email'], 'senha' => 'web-nova-senha-local']));
    apiCheck($changed['status'] === 302, 'Web password update');
    $server->api('/api/v1/me', 401, 'GET', ['Authorization: Bearer ' . $h['access_token']]);
    $logout = $server->request('/logout', 'GET', ['Cookie: ' . $cookie]);
    apiCheck($logout['status'] === 302, 'Web logout');
    $form = $server->request('/cadastro');
    preg_match('/name="_token" value="([^"]+)"/', $form['body'], $csrf);
    $cookie = explode(';', $form['headers']['set-cookie'][0])[0];
    $registered = $server->request('/cadastro', 'POST', ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(array_replace($account, ['email' => 'web@teste.invalid', '_token' => $csrf[1]])));
    apiCheck($registered['status'] === 302 && str_ends_with($registered['headers']['location'][0], '/login'), 'Web registration still redirects to login');
} finally {
    $server?->close();
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET search_path TO public');
    $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
}
