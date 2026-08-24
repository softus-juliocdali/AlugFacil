<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Models\Chacara;
use App\Services\AffiliateMonthlyFeePolicy;
use App\Services\FakeAsaasClient;
use App\Services\MensalidadeAnuncioService;

$ok = 0;
$fail = 0;
$check = static function (string $name, bool $condition) use (&$ok, &$fail): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $ok++ : $fail++;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(<<<'SQL'
CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nome TEXT,email TEXT,status TEXT);
CREATE TABLE proprietarios(id INTEGER PRIMARY KEY,usuario_id INTEGER,nome TEXT,email TEXT,telefone TEXT,status TEXT,afiliado_id INTEGER);
CREATE TABLE chacaras(id INTEGER PRIMARY KEY,proprietario_id INTEGER,nome TEXT,status_aprovacao TEXT,status_operacional TEXT);
CREATE TABLE mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,chacara_id INTEGER NOT NULL UNIQUE,proprietario_id INTEGER NOT NULL,ativa BOOLEAN NOT NULL,valor_centavos INTEGER,status TEXT NOT NULL,asaas_customer_id TEXT,asaas_subscription_id TEXT UNIQUE,proximo_vencimento TEXT,ultimo_pagamento_em TEXT,configurada_por INTEGER,ultima_falha_sincronizacao TEXT,ultima_tentativa_sincronizacao_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE cobrancas_mensalidades(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER NOT NULL,asaas_payment_id TEXT NOT NULL UNIQUE,asaas_event_id TEXT UNIQUE,valor_centavos INTEGER NOT NULL,status TEXT NOT NULL,vencimento TEXT,invoice_url TEXT,pago_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP,atualizada_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE historico_mensalidades_anuncios(id INTEGER PRIMARY KEY AUTOINCREMENT,mensalidade_id INTEGER,status_anterior TEXT,status_novo TEXT,valor_centavos INTEGER,origem TEXT,referencia_externa TEXT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notificacoes(id INTEGER PRIMARY KEY AUTOINCREMENT,usuario_id INTEGER,tipo TEXT,titulo TEXT,mensagem TEXT,link TEXT,chave_deduplicacao TEXT UNIQUE,lida_em TEXT,criada_em TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO usuarios VALUES
  (1,'Indicado','indicado@example.test','ativo'),
  (2,'Comum','comum@example.test','ativo'),
  (9,'Admin','admin@example.test','ativo');
INSERT INTO proprietarios VALUES
  (10,1,'Indicado','indicado@example.test','11911111111','ativo',77),
  (20,2,'Comum','comum@example.test','11922222222','ativo',NULL);
INSERT INTO chacaras VALUES
  (101,10,'Afiliada A','aprovada','disponivel'),
  (102,10,'Afiliada B','aprovada','disponivel'),
  (103,10,'Afiliada futura','pendente','indisponivel'),
  (201,20,'Comum sem mensalidade','aprovada','disponivel'),
  (202,20,'Comum com mensalidade','aprovada','disponivel');
SQL);

$affiliate = ['afiliado_id' => 77];
$common = ['afiliado_id' => null];
$check('01 vinculo por afiliado torna a mensalidade obrigatoria', AffiliateMonthlyFeePolicy::propertyRequiresMonthlyFee($affiliate));
$check('02 proprietario comum conserva a modalidade opcional', !AffiliateMonthlyFeePolicy::propertyRequiresMonthlyFee($common));
$check('03 status do afiliado nao participa da regra', AffiliateMonthlyFeePolicy::propertyRequiresMonthlyFee(['afiliado_id' => 77, 'afiliado_status' => 'bloqueado']));

$message = null;
try {
    AffiliateMonthlyFeePolicy::assertModeAllowed($affiliate, 'sem');
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
}
$check('04 policy rejeita modalidade sem mensalidade com mensagem explicita', $message === AffiliateMonthlyFeePolicy::REQUIRED_MESSAGE);

$fake = new FakeAsaasClient();
$fake->assinaturasRemotas = [
    ['id' => 'sub_101', 'externalReference' => 'mensalidade_chacara_101'],
    ['id' => 'sub_102', 'externalReference' => 'mensalidade_chacara_102'],
    ['id' => 'sub_103', 'externalReference' => 'mensalidade_chacara_103'],
    ['id' => 'sub_202', 'externalReference' => 'mensalidade_chacara_202'],
];
$service = new MensalidadeAnuncioService($pdo, $fake);

$serviceMessage = null;
try {
    $service->configurar(101, false, null, 9);
} catch (RuntimeException $exception) {
    $serviceMessage = $exception->getMessage();
}
$check('05 backend rejeita POST manipulado antes de persistir', $serviceMessage === AffiliateMonthlyFeePolicy::REQUIRED_MESSAGE && (int) $pdo->query('SELECT COUNT(*) FROM mensalidades_anuncios')->fetchColumn() === 0);

$monthlyA = $service->configurar(101, true, 4990, 9);
$monthlyB = $service->configurar(102, true, 5990, 9);
$monthlyFuture = $service->configurar(103, true, 6990, 9);
$check('06 todas as chacaras atuais e futuras do indicado usam mensalidade', $monthlyA['status'] === 'PENDENTE' && $monthlyB['status'] === 'PENDENTE' && $monthlyFuture['status'] === 'PENDENTE');
$check('07 multiplas chacaras possuem registros e assinaturas independentes', count(array_unique([$monthlyA['asaas_subscription_id'], $monthlyB['asaas_subscription_id'], $monthlyFuture['asaas_subscription_id']])) === 3 && (int) $pdo->query('SELECT COUNT(*) FROM mensalidades_anuncios WHERE proprietario_id=10')->fetchColumn() === 3);

$commonWithoutFee = $service->configurar(201, false, 999999, 9);
$commonWithFee = $service->configurar(202, true, 4990, 9);
$check('08 proprietario comum pode aprovar sem mensalidade', $commonWithoutFee['status'] === 'SEM_MENSALIDADE' && in_array($commonWithoutFee['ativa'], [false, 0, '0', 'false'], true));
$check('09 proprietario comum tambem pode optar pelo fluxo mensal existente', $commonWithFee['status'] === 'PENDENTE' && !empty($commonWithFee['ativa']));

$baseAffiliate = ['status_aprovacao' => 'aprovada', 'status_operacional' => 'disponivel', 'usuario_status' => 'ativo', 'afiliado_id' => 77];
$baseCommon = array_replace($baseAffiliate, ['afiliado_id' => null]);
$check('10 indicado sem mensalidade nunca e elegivel publicamente', !Chacara::elegivelPublicamente($baseAffiliate, null) && !Chacara::elegivelPublicamente($baseAffiliate, ['ativa' => false, 'status' => 'SEM_MENSALIDADE']));
$check('11 indicado pendente permanece fora da vitrine', !Chacara::elegivelPublicamente($baseAffiliate, ['ativa' => true, 'status' => 'PENDENTE']));
$check('12 indicado em dia pode aparecer na vitrine', Chacara::elegivelPublicamente($baseAffiliate, ['ativa' => true, 'status' => 'EM_DIA']));
$check('13 regra publica anterior permanece para proprietario comum', Chacara::elegivelPublicamente($baseCommon, ['ativa' => false, 'status' => 'SEM_MENSALIDADE']));

$clause = Chacara::clausulaElegibilidadePublica();
$pdo->exec("UPDATE mensalidades_anuncios SET ativa=CASE WHEN status='SEM_MENSALIDADE' THEN 0 ELSE 1 END");
$publicIds = array_map('intval', $pdo->query("SELECT c.id FROM chacaras c INNER JOIN proprietarios p ON p.id=c.proprietario_id INNER JOIN usuarios u ON u.id=p.usuario_id WHERE $clause ORDER BY c.id")->fetchAll(PDO::FETCH_COLUMN));
$check('14 SQL exige EM_DIA para todas as chacaras do indicado', $publicIds === [201]);
$check('15 clausula central consulta diretamente o vinculo do proprietario', str_contains($clause, 'p.afiliado_id IS NULL OR EXISTS') && str_contains($clause, "ma_afiliado.status='EM_DIA'"));

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/controllers/AdminController.php');
$serviceCode = (string) file_get_contents($root . '/app/services/MensalidadeAnuncioService.php');
$view = (string) file_get_contents($root . '/app/views/admin/chacaras/show.php');
$check('16 controller e servico aplicam a mesma policy central', str_contains($controller, 'AffiliateMonthlyFeePolicy::assertModeAllowed') && str_contains($serviceCode, 'AffiliateMonthlyFeePolicy::assertConfigurationAllowed'));
$check('17 tela oculta sem mensalidade e mantem com mensalidade para indicado', str_contains($view, 'if(!$mensalidadeObrigatoriaAfiliado)') && str_contains($view, '$mensalidadeObrigatoriaAfiliado||$mensalidadeAtiva') && str_contains($view, 'Mensalidade obrigatória'));
$check('18 implementacao nao cria comissao nem repasse antecipado', !str_contains($serviceCode, 'comissao') && !str_contains($serviceCode, 'repasse'));

echo 'Total: ' . ($ok + $fail) . " | Aprovados: $ok | Falhos: $fail" . PHP_EOL;
exit($fail ? 1 : 0);
