<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\Affiliate;
use App\Services\AffiliateAttributionService;
use App\Services\AffiliateCommissionService;
use App\Services\AffiliateMonthlyFeePolicy;
use App\Services\AffiliatePortalService;
use App\Services\MensalidadeAnuncioService;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
}

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$tag = (string) random_int(10000000, 99999999);
$codeA = 'AF8' . $tag . '1';
$codeB = 'AF8' . $tag . '2';
$emailA = 'audit.affiliate.a.' . $tag . '@test.local';
$emailB = 'audit.affiliate.b.' . $tag . '@test.local';
$password = 'AuditoriaSegura123!';
$db->beginTransaction();

try {
    $userInsert = $db->prepare(
        "INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status)
         VALUES(:nome,:email,'hash',:tipo,'ativo') RETURNING id"
    );
    $userInsert->execute(['nome' => 'Admin Auditoria', 'email' => 'audit.admin.' . $tag . '@test.local', 'tipo' => 'admin']);
    $adminId = (int) $userInsert->fetchColumn();

    $affiliateInsert = $db->prepare(
        "INSERT INTO afiliados
            (codigo,nome,cpf_cnpj,telefone,email,senha_hash,chave_pix,tipo_chave_pix,status)
         VALUES
            (:codigo,:nome,:documento,'11999999999',:email,:senha,:pix,'aleatoria','ativo')
         RETURNING id"
    );
    $affiliateInsert->execute([
        'codigo' => $codeA,
        'nome' => 'Afiliado Auditoria A',
        'documento' => '39053344705',
        'email' => $emailA,
        'senha' => password_hash($password, PASSWORD_DEFAULT),
        'pix' => 'audit-pix-a-' . $tag,
    ]);
    $affiliateA = (int) $affiliateInsert->fetchColumn();
    $affiliateInsert->execute([
        'codigo' => $codeB,
        'nome' => 'Afiliado Auditoria B',
        'documento' => '52998224725',
        'email' => $emailB,
        'senha' => password_hash($password, PASSWORD_DEFAULT),
        'pix' => 'audit-pix-b-' . $tag,
    ]);
    $affiliateB = (int) $affiliateInsert->fetchColumn();
    $check('01 cria Afiliado A', $affiliateA > 0);
    $check('02 cria Afiliado B independente', $affiliateB > 0 && $affiliateB !== $affiliateA);

    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    $attributionService = new AffiliateAttributionService(new Affiliate(), strtotime('2026-08-01T10:00:00-03:00'), false);
    $captured = $attributionService->captureFromQuery($codeA);
    $check('03 captura link real do Afiliado A', ($captured['codigo'] ?? null) === $codeA && ($captured['origem'] ?? null) === 'link');
    $resolved = $attributionService->resolveForRegistration($codeB);
    $check('04 first attribution wins ignora troca manual para B', ($resolved['attribution']['afiliado_id'] ?? null) === $affiliateA);

    $ownerUserEmail = 'audit.owner.a.' . $tag . '@test.local';
    $userInsert->execute(['nome' => 'Proprietario Auditoria A', 'email' => $ownerUserEmail, 'tipo' => 'proprietario']);
    $ownerUserA = (int) $userInsert->fetchColumn();
    $ownerInsert = $db->prepare(
        "INSERT INTO proprietarios
            (usuario_id,nome,email,status,afiliado_id,afiliado_origem,afiliado_atribuido_em)
         VALUES(:usuario,:nome,:email,'ativo',:afiliado,:origem,:atribuido) RETURNING id"
    );
    $ownerInsert->execute([
        'usuario' => $ownerUserA,
        'nome' => 'Proprietario Auditoria A',
        'email' => $ownerUserEmail,
        'afiliado' => $resolved['attribution']['afiliado_id'],
        'origem' => $resolved['attribution']['origem'],
        'atribuido' => $resolved['attribution']['atribuido_em'],
    ]);
    $ownerA = (int) $ownerInsert->fetchColumn();
    $attributionService->clearPending();
    $linked = $db->query("SELECT afiliado_id,afiliado_origem FROM proprietarios WHERE id={$ownerA}")->fetch();
    $check('05 cadastra Proprietario 1 e remove cookie somente apos sucesso', (int) $linked['afiliado_id'] === $affiliateA && $linked['afiliado_origem'] === 'link' && !isset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]));
    $check('06 confirma vinculo persistido com A', (int) $linked['afiliado_id'] === $affiliateA);

    $userInsert->execute(['nome' => 'Proprietario Auditoria B', 'email' => 'audit.owner.b.' . $tag . '@test.local', 'tipo' => 'proprietario']);
    $ownerUserB = (int) $userInsert->fetchColumn();
    $ownerInsert->execute([
        'usuario' => $ownerUserB,
        'nome' => 'Proprietario Auditoria B',
        'email' => 'audit.owner.b.' . $tag . '@test.local',
        'afiliado' => $affiliateB,
        'origem' => 'codigo',
        'atribuido' => '2026-08-01T10:00:00-03:00',
    ]);
    $ownerB = (int) $ownerInsert->fetchColumn();

    $propertyInsert = $db->prepare(
        "INSERT INTO chacaras
            (proprietario_id,nome,valor_diaria,cidade,estado,endereco,status,status_aprovacao,status_operacional)
         VALUES(:owner,:nome,100,'Campinas','SP','Rua Auditoria','disponivel','aprovada','disponivel')
         RETURNING id"
    );
    $propertyInsert->execute(['owner' => $ownerA, 'nome' => 'Chacara Auditoria A1']);
    $propertyA1 = (int) $propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner' => $ownerA, 'nome' => 'Chacara Auditoria A2']);
    $propertyA2 = (int) $propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner' => $ownerB, 'nome' => 'Chacara Auditoria B']);
    $propertyB = (int) $propertyInsert->fetchColumn();
    $check('07 cadastra duas chacaras do proprietario indicado', $propertyA1 > 0 && $propertyA2 > 0);

    $withoutMonthlyRejected = false;
    try {
        AffiliateMonthlyFeePolicy::assertConfigurationAllowed(['afiliado_id' => $affiliateA], false);
    } catch (RuntimeException $exception) {
        $withoutMonthlyRejected = $exception->getMessage() === AffiliateMonthlyFeePolicy::REQUIRED_MESSAGE;
    }
    $check('08 tenta configurar chácara indicada sem mensalidade', $withoutMonthlyRejected);
    $check('09 backend rejeita modalidade SEM_MENSALIDADE', $withoutMonthlyRejected);

    $monthlyInsert = $db->prepare(
        "INSERT INTO mensalidades_anuncios
            (chacara_id,proprietario_id,ativa,valor_centavos,status,asaas_subscription_id)
         VALUES(:chacara,:owner,TRUE,:valor,'PENDENTE',:subscription) RETURNING id"
    );
    $monthlyInsert->execute(['chacara' => $propertyA1, 'owner' => $ownerA, 'valor' => 10000, 'subscription' => 'sub_a1_' . $tag]);
    $monthlyA1 = (int) $monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara' => $propertyA2, 'owner' => $ownerA, 'valor' => 20000, 'subscription' => 'sub_a2_' . $tag]);
    $monthlyA2 = (int) $monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara' => $propertyB, 'owner' => $ownerB, 'valor' => 10000, 'subscription' => 'sub_b_' . $tag]);
    $monthlyInsert->fetchColumn();
    $check('10 configura ambas as chácaras de A com mensalidade independente', $monthlyA1 > 0 && $monthlyA2 > 0 && $monthlyA1 !== $monthlyA2);

    $db->exec('UPDATE configuracoes_comissao_afiliados SET percentual_bps=1000 WHERE id=1');
    $monthlyService = new MensalidadeAnuncioService($db);
    $finance = new AffiliateCommissionService($db);
    $event = static fn (string $id, string $payment): array => ['asaas_event_id' => $id, 'asaas_payment_id' => $payment];
    $payment = static fn (string $id, string $subscription, int $value, string $confirmed): array => [
        'id' => $id,
        'subscription' => $subscription,
        'value' => number_format($value / 100, 2, '.', ''),
        'status' => 'RECEIVED',
        'confirmedDate' => $confirmed,
    ];
    $paymentA1 = $payment('pay_a1_' . $tag, 'sub_a1_' . $tag, 10000, '2026-08-01T10:00:00-03:00');
    $paymentA2 = $payment('pay_a2_' . $tag, 'sub_a2_' . $tag, 20000, '2026-08-01T11:00:00-03:00');
    $monthlyService->processarPagamento($event('evt_a1_' . $tag, $paymentA1['id']), $paymentA1, 'PAYMENT_CONFIRMED');
    $commissionA1 = $db->query("SELECT * FROM comissoes_afiliados WHERE asaas_payment_id='" . $paymentA1['id'] . "'")->fetch();
    $check('11 simula pagamento confirmado da primeira chácara', $commissionA1 !== false);
    $check('12 primeira comissão usa R$ 100, 10% e produz R$ 10', (int) $commissionA1['valor_base_centavos'] === 10000 && (int) $commissionA1['percentual_bps'] === 1000 && (int) $commissionA1['valor_comissao_centavos'] === 1000);
    $monthlyService->processarPagamento($event('evt_a2_' . $tag, $paymentA2['id']), $paymentA2, 'PAYMENT_RECEIVED');
    $commissionA2 = $db->query("SELECT * FROM comissoes_afiliados WHERE asaas_payment_id='" . $paymentA2['id'] . "'")->fetch();
    $check('13 simula pagamento da segunda chácara', $commissionA2 !== false);
    $check('14 segunda comissão permanece associada exclusivamente à segunda chácara', (int) $commissionA2['chacara_id'] === $propertyA2 && (int) $commissionA2['valor_comissao_centavos'] === 2000);

    $beforeSevenDays = $finance->summary($affiliateA, new DateTimeImmutable('2026-08-08T09:59:59-03:00'));
    $exactSevenDays = $finance->summary($affiliateA, new DateTimeImmutable('2026-08-08T11:00:00-03:00'));
    $check('15 avança até a fronteira de sete dias', $beforeSevenDays['em_aberto_centavos'] === 3000 && $beforeSevenDays['saldo_disponivel_centavos'] === 0);
    $check('16 exatamente sete dias libera o saldo', $exactSevenDays['saldo_disponivel_centavos'] === 3000 && $exactSevenDays['em_aberto_centavos'] === 0);

    $manualPayment = $finance->registerManualPayment(
        $affiliateA,
        1500,
        '2026-08-08',
        'AUDIT-' . $tag,
        'Pagamento parcial transversal',
        $adminId,
        new DateTimeImmutable('2026-08-08T11:00:00-03:00')
    );
    $check('17 realiza pagamento parcial de R$ 15', (int) $manualPayment['valor_centavos'] === 1500);
    $allocations = $db->query(
        'SELECT a.valor_centavos,c.chacara_id FROM alocacoes_pagamentos_afiliados a '
        . 'INNER JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id '
        . 'WHERE a.pagamento_afiliado_id=' . (int) $manualPayment['id'] . ' ORDER BY c.disponivel_em,c.id'
    )->fetchAll();
    $check('18 FIFO liquida A1 e aloca o restante em A2', count($allocations) === 2 && (int) $allocations[0]['valor_centavos'] === 1000 && (int) $allocations[1]['valor_centavos'] === 500);

    $refundA2 = $paymentA2;
    $refundA2['status'] = 'REFUNDED';
    $monthlyService->processarPagamento($event('evt_refund_a2_' . $tag, $refundA2['id']), $refundA2, 'PAYMENT_REFUNDED');
    $check('19 estorna a segunda cobrança', $db->query('SELECT status FROM comissoes_afiliados WHERE id=' . (int) $commissionA2['id'])->fetchColumn() === 'ESTORNADA');
    $adjustment = $db->query('SELECT valor_centavos FROM ajustes_afiliados WHERE comissao_afiliado_id=' . (int) $commissionA2['id'])->fetchColumn();
    $check('20 ajuste negativo cobre somente os R$ 5 efetivamente pagos', (int) $adjustment === -500);

    $affiliateModel = new Affiliate();
    $authenticated = Affiliate::canAuthenticate($affiliateModel->findByEmail($emailA), $password);
    $check('21 autentica Afiliado A enquanto ativo', $authenticated);
    $portal = new AffiliatePortalService($db);
    $dashboard = $portal->dashboard($affiliateA, new DateTimeImmutable('2026-08-08T12:00:00-03:00'));
    $check('22 dashboard de A reflete proprietário, duas chácaras e ledger', $dashboard['proprietarios'] === 1 && $dashboard['chacaras'] === 2 && $dashboard['total_pago_centavos'] === 1500 && $dashboard['ajustes_centavos'] === -500);
    $owners = $portal->referredOwners($affiliateA);
    $check('23 lista de indicados de A contém somente Proprietário 1', $owners['total'] === 1 && (int) $owners['items'][0]['id'] === $ownerA);
    $commissions = $portal->commissions($affiliateA, [], 1, 30, new DateTimeImmutable('2026-08-08T12:00:00-03:00'));
    $check('24 portal lista as duas comissões de A', $commissions['total'] === 2);
    $check('25 tentativa de acessar proprietário de B é negada por IDOR', $portal->referredOwner($affiliateA, $ownerB) === null);
    $check('26 tentativa inversa de B acessar proprietário de A também é negada', $portal->referredOwner($affiliateB, $ownerA) === null);

    $db->prepare("UPDATE afiliados SET status='bloqueado' WHERE id=:id")->execute(['id' => $affiliateA]);
    $check('27 bloqueia Afiliado A', $db->query('SELECT status FROM afiliados WHERE id=' . $affiliateA)->fetchColumn() === 'bloqueado');
    $check('28 novo login de A é negado', !Affiliate::canAuthenticate($affiliateModel->findByEmail($emailA), $password));
    $paymentAfterBlock = $payment('pay_after_block_' . $tag, 'sub_a1_' . $tag, 10000, '2026-09-01T10:00:00-03:00');
    $monthlyService->processarPagamento($event('evt_after_block_' . $tag, $paymentAfterBlock['id']), $paymentAfterBlock, 'PAYMENT_RECEIVED');
    $check('29 processa nova mensalidade de proprietário já vinculado após bloqueio', (int) $db->query("SELECT COUNT(*) FROM cobrancas_mensalidades WHERE asaas_payment_id='" . $paymentAfterBlock['id'] . "'")->fetchColumn() === 1);
    $check('30 vínculo histórico bloqueado continua gerando comissão', (int) $db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE asaas_payment_id='" . $paymentAfterBlock['id'] . "' AND afiliado_id=" . $affiliateA)->fetchColumn() === 1);

    $monthlyService->processarPagamento($event('evt_late_a2_' . $tag, $paymentA2['id']), $paymentA2, 'PAYMENT_CONFIRMED');
    $check('31 confirmação atrasada não reativa comissão estornada', $db->query('SELECT status FROM comissoes_afiliados WHERE id=' . (int) $commissionA2['id'])->fetchColumn() === 'ESTORNADA');
} finally {
    unset($_COOKIE[AffiliateAttributionService::COOKIE_NAME]);
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$rollbackCount = (int) $db->query(
    "SELECT (SELECT COUNT(*) FROM afiliados WHERE codigo IN ('{$codeA}','{$codeB}'))"
    . " + (SELECT COUNT(*) FROM usuarios WHERE email LIKE 'audit.%{$tag}@test.local')"
    . " + (SELECT COUNT(*) FROM cobrancas_mensalidades WHERE asaas_payment_id LIKE '%{$tag}%')"
)->fetchColumn();
$check('32 rollback completo remove todos os dados isolados', $rollbackCount === 0);

echo 'Total: ' . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
