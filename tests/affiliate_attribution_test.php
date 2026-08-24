<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\Affiliate;
use App\Models\User;
use App\Services\AffiliateAttributionService;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
}

$passed = 0;
$failed = 0;
$checks = [];
$ownerIds = [];
$userIds = [];
$affiliateIds = [];
$now = 1_800_000_000;
$tag = (string) random_int(90000000, 99999999);
$codeA = 'AF' . $tag . '1';
$codeB = 'AF' . $tag . '2';
$codeBlocked = 'AF' . $tag . '3';

$insertAffiliate = $db->prepare(
    'INSERT INTO afiliados
        (codigo,nome,cpf_cnpj,telefone,email,senha_hash,chave_pix,tipo_chave_pix,status)
     VALUES
        (:codigo,:nome,:documento,:telefone,:email,:senha_hash,:chave_pix,:tipo_chave_pix,:status)
     RETURNING id'
);

try {
    foreach ([
        [$codeA, 'ativo', '1'],
        [$codeB, 'ativo', '2'],
        [$codeBlocked, 'bloqueado', '3'],
    ] as [$code, $status, $suffix]) {
        $insertAffiliate->execute([
            'codigo' => $code,
            'nome' => 'Afiliado Teste ' . $suffix,
            'documento' => '90000' . $tag . $suffix,
            'telefone' => '1199999999' . $suffix,
            'email' => strtolower($code) . '@localhost.test',
            'senha_hash' => password_hash('TesteLocal123!', PASSWORD_DEFAULT),
            'chave_pix' => strtolower($code) . '@localhost.test',
            'tipo_chave_pix' => 'email',
            'status' => $status,
        ]);
        $affiliateIds[$code] = (int) $insertAffiliate->fetchColumn();
    }

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $service = new AffiliateAttributionService(new Affiliate(), $now, false);

    $manual = $service->resolveForRegistration(strtolower($codeA));
    $checks['codigo_manual_valido'] = $manual['error'] === null
        && $manual['attribution']['afiliado_id'] === $affiliateIds[$codeA]
        && $manual['attribution']['origem'] === 'codigo';
    $checks['codigo_manual_inexistente'] = $service->resolveForRegistration('AF999999999999')['error'] === 'Código de afiliado inválido.';
    $checks['codigo_manual_bloqueado'] = $service->resolveForRegistration($codeBlocked)['error'] === 'Código de afiliado inválido.';
    $checks['codigo_manual_vazio'] = $service->resolveForRegistration('')['attribution'] === null;

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $link = $service->captureFromQuery(strtolower($codeA));
    $savedCookie = $_COOKIE[AffiliateAttributionService::COOKIE_NAME] ?? '';
    $checks['link_valido_capturado'] = $link !== null && $link['codigo'] === $codeA && $link['origem'] === 'link' && $savedCookie !== '';

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $checks['link_inexistente_ignorado'] = $service->captureFromQuery('AF999999999999') === null
        && !isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $checks['link_bloqueado_ignorado'] = $service->captureFromQuery($codeBlocked) === null
        && !isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);

    $_COOKIE[AffiliateAttributionService::COOKIE_NAME] = $savedCookie;
    $tenDaysLater = new AffiliateAttributionService(new Affiliate(), $now + (10 * 86400), false);
    $pendingLater = $tenDaysLater->readPending();
    $checks['indicacao_persiste'] = $pendingLater['state'] === 'valid' && $pendingLater['attribution']['codigo'] === $codeA;
    $checks['retorno_formulario_recupera_codigo'] = $tenDaysLater->codeForForm($pendingLater['attribution']) === $codeA;

    $_COOKIE[AffiliateAttributionService::COOKIE_NAME] = $savedCookie;
    $expiredService = new AffiliateAttributionService(new Affiliate(), $now + AffiliateAttributionService::TTL_SECONDS, false);
    $checks['expiracao_logica_30_dias'] = $expiredService->readPending()['state'] === 'expired';
    $expiredResolution = $expiredService->resolveForRegistration($codeB);
    $checks['cookie_expirado_nao_vincula_no_post'] = $expiredResolution['attribution'] === null
        && !isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);

    $_COOKIE[AffiliateAttributionService::COOKIE_NAME] = $savedCookie . 'alterado';
    $tamperedResolution = (new AffiliateAttributionService(new Affiliate(), $now, false))->resolveForRegistration($codeB);
    $checks['cookie_adulterado_e_rejeitado'] = $tamperedResolution['attribution']['codigo'] === $codeB;

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $firstWins = new AffiliateAttributionService(new Affiliate(), $now, false);
    $firstWins->captureFromQuery($codeA);
    $firstCookie = $_COOKIE[AffiliateAttributionService::COOKIE_NAME];
    $secondAttempt = $firstWins->captureFromQuery($codeB);
    $checks['first_attribution_wins_link'] = $secondAttempt['codigo'] === $codeA
        && $_COOKIE[AffiliateAttributionService::COOKIE_NAME] === $firstCookie;
    $changedManual = $firstWins->resolveForRegistration($codeB);
    $checks['cookie_prevalece_sobre_codigo_alterado'] = $changedManual['attribution']['codigo'] === $codeA;

    $userModel = new User();
    $newOwner = static function (string $suffix) use ($tag): array {
        return [
            'nome' => 'Proprietario Atribuicao ' . $suffix,
            'telefone' => '11988887777',
            'email' => 'owner-attribution-' . $tag . '-' . $suffix . '@localhost.test',
            'senha' => 'TesteLocal123!',
        ];
    };

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $noAffiliateData = $newOwner('sem');
    $noAffiliateUserId = $userModel->createOwner($noAffiliateData, null);
    $userIds[] = $noAffiliateUserId;
    $noAffiliateOwner = $userModel->findOwnerByUserId($noAffiliateUserId);
    $ownerIds[] = (int) $noAffiliateOwner['id'];
    $checks['cadastro_sem_afiliado'] = $noAffiliateOwner['afiliado_id'] === null;

    $manualData = $newOwner('manual');
    $manualResolution = $service->resolveForRegistration($codeB);
    $manualUserId = $userModel->createOwner($manualData, $manualResolution['attribution']);
    $userIds[] = $manualUserId;
    $manualOwner = $userModel->findOwnerByUserId($manualUserId);
    $ownerIds[] = (int) $manualOwner['id'];
    $checks['cadastro_com_afiliado'] = (int) $manualOwner['afiliado_id'] === $affiliateIds[$codeB];
    $checks['origem_codigo_correta'] = $manualOwner['afiliado_origem'] === 'codigo' && $manualOwner['afiliado_atribuido_em'] !== null;

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $linkRegistration = new AffiliateAttributionService(new Affiliate(), $now, false);
    $linkRegistration->captureFromQuery($codeA);
    $linkResolution = $linkRegistration->resolveForRegistration($codeB);
    $linkData = $newOwner('link');
    $linkUserId = $userModel->createOwner($linkData, $linkResolution['attribution']);
    $userIds[] = $linkUserId;
    $linkOwner = $userModel->findOwnerByUserId($linkUserId);
    $ownerIds[] = (int) $linkOwner['id'];
    $linkRegistration->clearPending();
    $checks['relacionamento_link_correto'] = (int) $linkOwner['afiliado_id'] === $affiliateIds[$codeA]
        && $linkOwner['afiliado_origem'] === 'link';
    $checks['cookie_removido_apos_sucesso'] = !isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $failureService = new AffiliateAttributionService(new Affiliate(), $now, false);
    $failureService->captureFromQuery($codeA);
    $ownersBeforeFailure = (int) $db->query('SELECT COUNT(*) FROM proprietarios')->fetchColumn();
    try {
        $userModel->createOwner($linkData, $manualResolution['attribution']);
    } catch (Throwable) {
        // E-mail duplicado deve reverter toda a transacao.
    }
    $ownersAfterFailure = (int) $db->query('SELECT COUNT(*) FROM proprietarios')->fetchColumn();
    $checks['falha_nao_gera_relacionamento_parcial'] = $ownersBeforeFailure === $ownersAfterFailure;
    $checks['falha_preserva_cookie'] = isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $blockedAfterCookie = new AffiliateAttributionService(new Affiliate(), $now, false);
    $blockedAfterCookie->captureFromQuery($codeA);
    $db->prepare("UPDATE afiliados SET status='bloqueado' WHERE id=:id")->execute(['id' => $affiliateIds[$codeA]]);
    $blockedResolution = $blockedAfterCookie->resolveForRegistration($codeB);
    $blockedData = $newOwner('bloqueado-depois');
    $blockedUserId = $userModel->createOwner($blockedData, $blockedResolution['attribution']);
    $userIds[] = $blockedUserId;
    $blockedOwner = $userModel->findOwnerByUserId($blockedUserId);
    $ownerIds[] = (int) $blockedOwner['id'];
    $checks['bloqueado_apos_cookie_nao_vincula'] = $blockedResolution['error'] === null
        && $blockedOwner['afiliado_id'] === null;
    $db->prepare("UPDATE afiliados SET status='ativo' WHERE id=:id")->execute(['id' => $affiliateIds[$codeA]]);

    $existingOwnerId = (int) $noAffiliateOwner['id'];
    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $service->captureFromQuery($codeA);
    $stillUnattributed = $db->prepare('SELECT afiliado_id FROM proprietarios WHERE id=:id');
    $stillUnattributed->execute(['id' => $existingOwnerId]);
    $checks['sem_retroatribuicao'] = $stillUnattributed->fetchColumn() === null;

    $controllerSource = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/AuthController.php');
    $affiliateControllerSource = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/AffiliateController.php');
    $ownerViewSource = (string) file_get_contents(dirname(__DIR__) . '/app/views/auth/register-owner.php');
    $migrationSource = (string) file_get_contents(dirname(__DIR__) . '/database/affiliate_attribution_migration.sql');
    $serviceSource = (string) file_get_contents(dirname(__DIR__) . '/app/services/AffiliateAttributionService.php');
    $userSource = (string) file_get_contents(dirname(__DIR__) . '/app/models/User.php');
    $frontControllerSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    $webhookEntrySource = (string) file_get_contents(dirname(__DIR__) . '/public/webhook_asaas.php');
    $checks['id_direto_do_frontend_ignorado'] = !str_contains($controllerSource, "\$_POST['affiliate_id']")
        && !str_contains($controllerSource, "\$_POST['afiliado_id']");
    $checks['csrf_preservado'] = str_contains($controllerSource, 'verify_csrf();');
    $checks['cookie_com_flags_seguros'] = str_contains($serviceSource, "'httponly' => true")
        && str_contains($serviceSource, "'samesite' => 'Lax'")
        && str_contains($serviceSource, "'secure' => \$this->isHttps()");
    $previousAppEnv = getenv('APP_ENV');
    $previousCookieSecret = getenv('AFFILIATE_COOKIE_SECRET');
    putenv('APP_ENV=production');
    putenv('AFFILIATE_COOKIE_SECRET=');
    $missingProductionSecretRejected = false;
    try {
        require dirname(__DIR__) . '/app/config/config.php';
    } catch (RuntimeException $exception) {
        $missingProductionSecretRejected = $exception->getMessage() === 'Configuracao obrigatoria de seguranca ausente.';
    } finally {
        $previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousAppEnv);
        $previousCookieSecret === false
            ? putenv('AFFILIATE_COOKIE_SECRET')
            : putenv('AFFILIATE_COOKIE_SECRET=' . $previousCookieSecret);
    }
    $checks['producao_sem_segredo_falha_com_seguranca'] = $missingProductionSecretRejected;
    $checks['falha_de_configuracao_nao_exibe_detalhes_publicos'] =
        strpos($frontControllerSource, "ini_set('display_errors', '0')") < strpos($frontControllerSource, "\$config = require APP_ROOT . '/app/config/config.php'")
        && strpos($webhookEntrySource, "ini_set('display_errors','0')") < strpos($webhookEntrySource, "\$app=require APP_ROOT.'/app/config/config.php'");
    $checks['revalidacao_transacional_bloqueia_corrida'] = str_contains($userSource, "status = 'ativo' FOR SHARE");
    $checks['link_aponta_para_cadastro_real'] = str_contains($affiliateControllerSource, '/cadastro-proprietario?ref=');
    $checks['formulario_publico_unico_recebe_codigo'] = str_contains($ownerViewSource, 'name="codigo_afiliado"')
        && str_contains($ownerViewSource, "url('/cadastro-proprietario')");
    $checks['migration_sem_backfill'] = !preg_match('/\bUPDATE\s+proprietarios\b/i', $migrationSource);
    $checks['etapa_nao_altera_financeiro'] = !preg_match('/\b(?:mensalidades|comissoes|pagamentos|asaas)\b/i', $migrationSource);

    foreach ($checks as $name => $condition) {
        echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
        $condition ? $passed++ : $failed++;
    }
} finally {
    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    foreach ($ownerIds as $ownerId) {
        $db->prepare('DELETE FROM proprietarios WHERE id=:id')->execute(['id' => $ownerId]);
    }
    foreach ($userIds as $userId) {
        $db->prepare('DELETE FROM usuarios WHERE id=:id')->execute(['id' => $userId]);
    }
    foreach (array_values($affiliateIds) as $affiliateId) {
        $db->prepare('DELETE FROM afiliados WHERE id=:id')->execute(['id' => $affiliateId]);
    }
}

echo 'Total: ' . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
