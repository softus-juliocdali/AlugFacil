<?php
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;
use App\Services\AsaasSubcontaService;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
}
$columnLength = $db->query("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name='aceites_financeiros_proprietarios' AND column_name='versao_documento'")->fetchColumn();
echo ((int) $columnLength === 100 ? '[OK]' : '[FALHA]') . " versao_documento varchar(100)\n";

$tag = bin2hex(random_bytes(4));
$email = "prompt-06-2-$tag@localhost.test";
$userId = 0;
$ownerId = 0;
$cookie = tempnam(sys_get_temp_dir(), 'af_prompt_06_2_');
$base = 'http://127.0.0.1:8000';
$request = static function (string $method, string $url, array $data = []) use ($cookie): array {
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
    return [$status, $parts[1] ?? ''];
};
$token = static function (string $body): string {
    if (!preg_match('/name="_token" value="([^"]+)"/', $body, $matches)) {
        throw new RuntimeException('Token CSRF nao encontrado.');
    }
    return html_entity_decode($matches[1]);
};
$data = [
    'tipo_pessoa' => 'PF', 'cpf_cnpj' => '11144477735',
    'nome_razao_social' => 'Teste Prompt 06.2', 'nome_fantasia' => '',
    'data_nascimento' => '1990-01-01', 'tipo_empresa' => '',
    'renda_faturamento_mensal' => '5.000,00', 'telefone' => '1133334444',
    'celular' => '11999998888', 'email_financeiro' => $email,
    'cep' => '01001000', 'endereco' => 'Rua Teste', 'numero' => '10',
    'complemento' => '', 'bairro' => 'Centro', 'cidade' => 'Sao Paulo', 'estado' => 'SP',
];

try {
    $statement = $db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Teste Prompt 06.2',:email,:hash,'proprietario','ativo') RETURNING id");
    $statement->execute(['email' => $email, 'hash' => password_hash('SmokeLocal123!', PASSWORD_DEFAULT)]);
    $userId = (int) $statement->fetchColumn();
    $statement = $db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:user_id,'Teste Prompt 06.2',:email,'ativo') RETURNING id");
    $statement->execute(['user_id' => $userId, 'email' => $email]);
    $ownerId = (int) $statement->fetchColumn();

    [$status, $body] = $request('GET', $base . '/login');
    [$loginStatus] = $request('POST', $base . '/login', ['_token' => $token($body), 'email' => $email, 'senha' => 'SmokeLocal123!']);
    echo ($status === 200 && $loginStatus === 302 ? '[OK]' : '[FALHA]') . " login do proprietario\n";

    [$pageStatus, $page] = $request('GET', $base . '/proprietario/recebimentos');
    echo ($pageStatus === 200 && str_contains($page, 'Dados financeiros') ? '[OK]' : '[FALHA]') . " acesso a recebimentos\n";
    [$saveStatus] = $request('POST', $base . '/proprietario/recebimentos/dados', ['_token' => $token($page)] + $data);
    echo ($saveStatus === 302 ? '[OK]' : '[FALHA]') . " salvar dados financeiros\n";

    [, $page] = $request('GET', $base . '/proprietario/recebimentos');
    [$acceptStatus] = $request('POST', $base . '/proprietario/recebimentos/aceite', ['_token' => $token($page), 'aceite_explicito' => '1']);
    echo ($acceptStatus === 302 ? '[OK]' : '[FALHA]') . " registrar autorizacao via HTTP\n";
    [$finalStatus, $finalPage] = $request('GET', $base . '/proprietario/recebimentos');
    $query = $db->prepare("SELECT versao_documento, texto_hash FROM aceites_financeiros_proprietarios WHERE proprietario_id=:id AND tipo_aceite='ONBOARDING_ASAAS_NON_BAAS' AND revogado_em IS NULL");
    $query->execute(['id' => $ownerId]);
    $acceptance = $query->fetch();
    $saved = $acceptance && $acceptance['versao_documento'] === 'operacional-v1-revisao-juridica-pendente' && hash_equals(AsaasSubcontaService::aceiteHash(), (string) $acceptance['texto_hash']);
    echo ($saved ? '[OK]' : '[FALHA]') . " aceite vigente confirmado no banco\n";
    echo ($finalStatus === 200 && str_contains($finalPage, 'Autorizacao financeira registrada e vigente.') ? '[OK]' : '[FALHA]') . " status visual registrado\n";
} finally {
    @unlink($cookie);
    if ($ownerId) {
        foreach (['auditoria_dados_financeiros', 'aceites_financeiros_proprietarios', 'proprietario_dados_financeiros'] as $table) {
            $db->prepare("DELETE FROM $table WHERE proprietario_id=:id")->execute(['id' => $ownerId]);
        }
        $db->prepare('DELETE FROM proprietarios WHERE id=:id')->execute(['id' => $ownerId]);
    }
    if ($userId) {
        $db->prepare('DELETE FROM usuarios WHERE id=:id')->execute(['id' => $userId]);
    }
}
