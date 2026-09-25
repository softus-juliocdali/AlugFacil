<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $parts = explode('\\', substr($class, 4));
    $parts[0] = strtolower($parts[0]);
    $file = APP_ROOT . '/app/' . implode('/', $parts) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Auth;
use App\Core\Database;
use App\Models\Chacara;
use App\Models\Reserva;
use App\Models\User;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
}

$tag = bin2hex(random_bytes(5));
$ownerEmail = "owner-flow-{$tag}@localhost.test";
$adminEmail = "admin-flow-{$tag}@localhost.test";
$userId = 0;
$ownerId = 0;
$adminId = 0;
$chacaraId = 0;
$checks = [];

try {
    $userModel = new User();
    $userId = $userModel->createOwner([
        'nome' => 'Proprietario Fluxo',
        'telefone' => '11999999999',
        'email' => $ownerEmail,
        'senha' => 'TesteLocal123!',
    ]);
    $owner = $userModel->findOwnerByUserId($userId);
    $ownerId = (int) ($owner['id'] ?? 0);

    $checks['cadastro_cria_usuario_ativo'] = $userModel->findById($userId)['status'] === 'ativo';
    $checks['cadastro_cria_proprietario_ativo'] = ($owner['status'] ?? null) === 'ativo';
    $checks['login_redireciona_dashboard'] = Auth::redirectPath('proprietario') === '/proprietario/dashboard';

    // Simula cadastros legados ainda marcados como pendentes.
    $db->prepare("UPDATE proprietarios SET status = 'pendente' WHERE id = :id")
        ->execute(['id' => $ownerId]);
    $_SESSION['user'] = [
        'id' => $userId,
        'nome' => 'Proprietario Fluxo',
        'email' => $ownerEmail,
        'role' => 'proprietario',
        'status' => 'ativo',
    ];
    $autenticado = Auth::requireProprietarioOperacional();
    $checks['proprietario_pendente_acessa_painel'] = (int) $autenticado['id'] === $ownerId;

    $chacaraModel = new Chacara();
    $chacaraId = $chacaraModel->criarParaProprietario($ownerId, [
        'nome' => 'Chacara Fluxo',
        'descricao' => 'Teste da separacao entre conta e anuncio.',
        'tipo_imovel' => 'chacara',
        'valor_diaria' => '250.00',
        'cidade' => 'Sorocaba',
        'regiao' => 'Centro',
        'endereco' => 'Rua do Teste, 10',
        'latitude' => null,
        'longitude' => null,
        'checkin_hora_inicial' => '14:00',
        'checkin_hora_final' => '18:00',
        'checkout_hora_inicial' => '08:00',
        'checkout_hora_final' => '11:00',
    ]);
    $nova = $chacaraModel->buscarDoProprietario($chacaraId, $ownerId);
    $checks['chacara_nova_fica_pendente'] = ($nova['status_aprovacao'] ?? null) === 'pendente'
        && ($nova['status_operacional'] ?? null) === 'indisponivel';
    $checks['chacara_pendente_nao_publica'] = $chacaraModel->buscarPerfil($chacaraId) === null;
    $checks['dono_segue_acessando_com_chacara_pendente'] =
        (int) Auth::requireProprietarioOperacional()['id'] === $ownerId;

    $statement = $db->prepare(
        "INSERT INTO usuarios (nome,email,senha_hash,tipo_usuario,status)
         VALUES ('Admin Fluxo',:email,'teste','admin','ativo') RETURNING id"
    );
    $statement->execute(['email' => $adminEmail]);
    $adminId = (int) $statement->fetchColumn();

    // Isolate access/approval from the global monthly-fee policy introduced in phase 4.
    $db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade,comissao_bps) VALUES(:c,TRUE,1000) ON CONFLICT(chacara_id) DO UPDATE SET sem_mensalidade=TRUE,comissao_bps=1000')->execute(['c'=>$chacaraId]);
    $checks['admin_aprova_chacara_de_dono_pendente'] =
        $chacaraModel->atualizarStatusAdministrativo($chacaraId, 'aprovada', 'Teste automatizado', $adminId);
    $checks['chacara_aprovada_publica'] = $chacaraModel->buscarPerfil($chacaraId) !== null;
    $checks['chacara_aprovada_pode_ser_reservada'] =
        (new Reserva())->buscarChacaraParaReserva($chacaraId) !== null;

    foreach ($checks as $name => $ok) {
        echo ($ok ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    }
} finally {
    unset($_SESSION['user']);
    if ($chacaraId > 0) {
        $db->prepare('DELETE FROM configuracoes_comerciais_imoveis WHERE chacara_id=:c')->execute(['c'=>$chacaraId]);
        $db->prepare('DELETE FROM historico_status_chacaras WHERE chacara_id = :id')
            ->execute(['id' => $chacaraId]);
        $db->prepare('DELETE FROM chacaras WHERE id = :id')->execute(['id' => $chacaraId]);
    }
    if ($ownerId > 0) {
        $db->prepare('DELETE FROM proprietarios WHERE id = :id')->execute(['id' => $ownerId]);
    }
    foreach ([$userId, $adminId] as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

exit(in_array(false, $checks, true) ? 1 : 0);
