<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
}

$base = 'http://127.0.0.1:8000';
$tag = (string) random_int(10000000, 99999999);
$password = 'HttpAudit123!';
$checks = [];
$userIds = [];
$ownerId = 0;
$affiliateIds = [];
$cookieFiles = [];

$request = static function (string $method, string $url, array $data, string $cookie): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
    ]);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    return [$status, $parts[1] ?? '', $raw];
};
$token = static function (string $body): string {
    if (!preg_match('/name="_token" value="([^"]+)"/', $body, $match)) {
        throw new RuntimeException('Token CSRF nao encontrado no formulario local.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};
$login = static function (string $path, string $email, string $cookie) use ($request, $token, $base, $password): int {
    [, $body] = $request('GET', $base . $path, [], $cookie);
    [$status] = $request('POST', $base . $path, [
        '_token' => $token($body),
        'email' => $email,
        'senha' => $password,
    ], $cookie);
    return $status;
};

try {
    $userInsert = $db->prepare(
        "INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status)
         VALUES(:nome,:email,:senha,:tipo,'ativo') RETURNING id"
    );
    $adminEmail = 'http.audit.admin.' . $tag . '@test.local';
    $ownerEmail = 'http.audit.owner.' . $tag . '@test.local';
    foreach ([['Admin HTTP', $adminEmail, 'admin'], ['Owner HTTP', $ownerEmail, 'proprietario']] as [$name, $email, $role]) {
        $userInsert->execute([
            'nome' => $name,
            'email' => $email,
            'senha' => password_hash($password, PASSWORD_DEFAULT),
            'tipo' => $role,
        ]);
        $userIds[] = (int) $userInsert->fetchColumn();
    }
    $ownerStatement = $db->prepare(
        "INSERT INTO proprietarios(usuario_id,nome,email,status)
         VALUES(:usuario,'Owner HTTP',:email,'ativo') RETURNING id"
    );
    $ownerStatement->execute(['usuario' => $userIds[1], 'email' => $ownerEmail]);
    $ownerId = (int) $ownerStatement->fetchColumn();

    $affiliateInsert = $db->prepare(
        "INSERT INTO afiliados
            (codigo,nome,cpf_cnpj,telefone,email,senha_hash,chave_pix,tipo_chave_pix,status)
         VALUES(:codigo,:nome,:documento,'11999999999',:email,:senha,:pix,'aleatoria',:status)
         RETURNING id"
    );
    $activeEmail = 'http.audit.affiliate.' . $tag . '@test.local';
    $blockedEmail = 'http.audit.blocked.' . $tag . '@test.local';
    foreach ([
        ['AF7' . $tag . '1', 'Afiliado HTTP', '39053344705', $activeEmail, 'ativo'],
        ['AF7' . $tag . '2', 'Afiliado HTTP Bloqueado', '52998224725', $blockedEmail, 'bloqueado'],
    ] as [$code, $name, $document, $email, $status]) {
        $affiliateInsert->execute([
            'codigo' => $code,
            'nome' => $name,
            'documento' => $document,
            'email' => $email,
            'senha' => password_hash($password, PASSWORD_DEFAULT),
            'pix' => 'http-audit-' . $code,
            'status' => $status,
        ]);
        $affiliateIds[] = (int) $affiliateInsert->fetchColumn();
    }

    foreach (['affiliate', 'admin', 'owner', 'blocked', 'invalid'] as $name) {
        $cookieFiles[$name] = tempnam(sys_get_temp_dir(), 'af_http_' . $name . '_');
    }

    [$invalidStatus] = $request('POST', $base . '/afiliado/login', [
        'email' => $activeEmail,
        'senha' => $password,
    ], $cookieFiles['invalid']);
    [$invalidDashboard] = $request('GET', $base . '/afiliado', [], $cookieFiles['invalid']);
    $checks['csrf_ausente_nao_autentica_afiliado'] = in_array($invalidStatus, [302, 419], true) && $invalidDashboard === 302;

    $checks['login_afiliado_ativo'] = $login('/afiliado/login', $activeEmail, $cookieFiles['affiliate']) === 302;
    [$affiliateDashboard] = $request('GET', $base . '/afiliado', [], $cookieFiles['affiliate']);
    [$affiliateInAdmin] = $request('GET', $base . '/admin/afiliados', [], $cookieFiles['affiliate']);
    $checks['sessao_afiliado_acessa_portal'] = $affiliateDashboard === 200;
    $checks['sessao_afiliado_nao_acessa_admin'] = $affiliateInAdmin === 302;

    $beforePhone = $db->query('SELECT telefone FROM afiliados WHERE id=' . $affiliateIds[0])->fetchColumn();
    [$badProfile] = $request('POST', $base . '/afiliado/perfil', [
        '_token' => 'invalido',
        'nome' => 'Nome Manipulado',
        'telefone' => '11000000000',
        'email' => $activeEmail,
        'chave_pix' => 'outra',
        'tipo_chave_pix' => 'aleatoria',
        'senha_atual' => $password,
    ], $cookieFiles['affiliate']);
    $afterPhone = $db->query('SELECT telefone FROM afiliados WHERE id=' . $affiliateIds[0])->fetchColumn();
    $checks['csrf_invalido_nao_altera_perfil'] = in_array($badProfile, [302, 419], true) && $beforePhone === $afterPhone;

    [$badLogout] = $request('POST', $base . '/afiliado/logout', ['_token' => 'invalido'], $cookieFiles['affiliate']);
    [$stillLogged] = $request('GET', $base . '/afiliado', [], $cookieFiles['affiliate']);
    $checks['csrf_invalido_nao_encerra_sessao'] = in_array($badLogout, [302, 419], true) && $stillLogged === 200;

    [, $profileBody] = $request('GET', $base . '/afiliado/perfil', [], $cookieFiles['affiliate']);
    [$validLogout] = $request('POST', $base . '/afiliado/logout', ['_token' => $token($profileBody)], $cookieFiles['affiliate']);
    [$afterLogout] = $request('GET', $base . '/afiliado', [], $cookieFiles['affiliate']);
    $checks['logout_valido_remove_sessao_afiliado'] = $validLogout === 302 && $afterLogout === 302;

    $checks['login_admin'] = $login('/login', $adminEmail, $cookieFiles['admin']) === 302;
    [$adminArea] = $request('GET', $base . '/admin/afiliados', [], $cookieFiles['admin']);
    [$adminInAffiliate] = $request('GET', $base . '/afiliado', [], $cookieFiles['admin']);
    $checks['admin_acessa_administracao'] = $adminArea === 200;
    $checks['sessao_admin_nao_concede_portal_afiliado'] = $adminInAffiliate === 302;

    $checks['login_proprietario'] = $login('/login', $ownerEmail, $cookieFiles['owner']) === 302;
    [$ownerInAdmin] = $request('GET', $base . '/admin/afiliados', [], $cookieFiles['owner']);
    [$ownerInAffiliate] = $request('GET', $base . '/afiliado', [], $cookieFiles['owner']);
    $checks['proprietario_nao_acessa_admin'] = $ownerInAdmin === 302;
    $checks['proprietario_nao_acessa_portal_afiliado'] = $ownerInAffiliate === 302;

    $blockedLogin = $login('/afiliado/login', $blockedEmail, $cookieFiles['blocked']);
    [$blockedDashboard] = $request('GET', $base . '/afiliado', [], $cookieFiles['blocked']);
    $checks['afiliado_bloqueado_nao_autentica'] = $blockedLogin === 302 && $blockedDashboard === 302;

    foreach ($checks as $name => $condition) {
        echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    }
} finally {
    foreach ($cookieFiles as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
    if ($ownerId > 0) {
        $db->prepare('DELETE FROM proprietarios WHERE id=:id')->execute(['id' => $ownerId]);
    }
    if ($userIds !== []) {
        $db->exec('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
    }
    if ($affiliateIds !== []) {
        $db->exec('DELETE FROM afiliados WHERE id IN (' . implode(',', array_map('intval', $affiliateIds)) . ')');
    }
}

$failed = count(array_filter($checks, static fn (bool $condition): bool => !$condition));
echo 'Total: ' . count($checks) . ' | Aprovados: ' . (count($checks) - $failed) . ' | Falhos: ' . $failed . PHP_EOL;
exit($failed > 0 ? 1 : 0);
