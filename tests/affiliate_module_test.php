<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Models\Affiliate;
use App\Core\AffiliateAuth;
use App\Validators\AffiliateValidator;

session_start();
$_SESSION['user'] = ['id' => 99, 'nome' => 'Admin'];
AffiliateAuth::login(['id' => 7, 'codigo' => 'AF0007', 'nome' => 'Afiliado', 'email' => 'a@example.com', 'status' => 'ativo']);
$sessionsCoexist = isset($_SESSION['user'], $_SESSION['affiliate']);
AffiliateAuth::logout();
$normalSessionSurvived = isset($_SESSION['user']) && !isset($_SESSION['affiliate']);

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$migration = $read('database/affiliate_migration.sql');
$model = $read('app/models/Affiliate.php');
$auth = $read('app/core/AffiliateAuth.php');
$authController = $read('app/controllers/AffiliateAuthController.php');
$adminController = $read('app/controllers/AdminAffiliateController.php');
$routes = $read('app/config/routes.php');
$frontController = $read('public/index.php');

$check('01 criacao de afiliado usa insert preparado', str_contains($model, 'INSERT INTO afiliados') && str_contains($model, 'password_hash'));
$check('02 codigo usa sequence PostgreSQL concorrente', str_contains($migration, 'CREATE SEQUENCE IF NOT EXISTS afiliados_codigo_seq') && str_contains($migration, "NEXTVAL('afiliados_codigo_seq')"));
$check('03 codigo segue formato AF0001', str_contains($migration, "'AF' || LPAD") && str_contains($migration, "'^AF[0-9]{4,}$'"));
$check('03a codigo acima de AF9999 nao e truncado', str_contains($migration, 'GREATEST(4, LENGTH(valor::TEXT))') && str_contains($migration, 'formatar_codigo_afiliado(NEXTVAL'));
$check('04 codigo e unico e imutavel', str_contains($migration, 'uq_afiliados_codigo UNIQUE') && str_contains($migration, 'trg_afiliados_codigo_imutavel'));
$check('05 e-mail e documento sao unicos', str_contains($migration, 'uq_afiliados_cpf_cnpj') && str_contains($migration, 'uq_afiliados_email_lower'));

$hash = password_hash('SenhaSegura123', PASSWORD_DEFAULT);
$active = ['senha_hash' => $hash, 'status' => 'ativo'];
$blocked = ['senha_hash' => $hash, 'status' => 'bloqueado'];
$check('06 login valido', Affiliate::canAuthenticate($active, 'SenhaSegura123'));
$check('07 login invalido', !Affiliate::canAuthenticate($active, 'senha-errada'));
$check('08 afiliado bloqueado nao autentica', !Affiliate::canAuthenticate($blocked, 'SenhaSegura123'));
$check('09 sessao do afiliado e independente', $sessionsCoexist && $normalSessionSurvived && str_contains($auth, "\$_SESSION['affiliate']") && !str_contains($auth, "\$_SESSION['user']"));
$check('10 rota protegida revalida status no banco', str_contains($auth, 'FROM afiliados WHERE id = :id') && str_contains($auth, "!== 'ativo'") && str_contains($authController, 'AffiliateAuth::login'));
$check('11 rotas publicas e protegida existem', str_contains($routes, "'/afiliado/login'") && str_contains($routes, "'/afiliado/logout'") && str_contains($routes, "'/afiliado'"));
$check('12 logout e POST com CSRF', str_contains($routes, "post('/afiliado/logout'") && str_contains($authController, "verify_csrf('/afiliado/login')"));
$check('13 administracao exige perfil admin', substr_count($adminController, "Auth::requireRole('admin')") >= 8);
$check('14 formularios administrativos usam PRG', str_contains($adminController, 'verify_csrf()') && str_contains($adminController, "redirect('/admin/afiliados"));

$check('15 CPF valido', AffiliateValidator::documentIsValid('529.982.247-25'));
$check('16 CPF invalido', !AffiliateValidator::documentIsValid('111.111.111-11'));
$check('17 CNPJ valido', AffiliateValidator::documentIsValid('04.252.011/0001-10'));
$check('18 percentual decimal vira basis points', AffiliateValidator::commissionToBasisPoints('10,25') === 1025);
$check('19 percentual deve ser maior que zero', AffiliateValidator::commissionToBasisPoints('0') === null);
$check('20 percentual maximo e 100', AffiliateValidator::commissionToBasisPoints('100,00') === 10000 && AffiliateValidator::commissionToBasisPoints('100,01') === null);
$check('21 percentual nao usa float no banco', str_contains($migration, 'percentual_bps SMALLINT') && !preg_match('/\b(?:REAL|FLOAT|DOUBLE)\b/i', $migration));
$check('22 configuracao global e singleton', str_contains($migration, 'chk_config_comissao_afiliado_unica CHECK (id = 1)'));
$check('23 migration incremental nao remove tabelas ou dados', !preg_match('/\b(?:DROP TABLE|TRUNCATE|DELETE FROM)\b/i', $migration));
$check('24 etapa nao cria comissoes, pagamentos ou vinculos', !preg_match('/CREATE TABLE IF NOT EXISTS (?:comissoes|pagamentos_afiliados|afiliados_proprietarios)/i', $migration));
$check('25 expiracao preserva destino de login do afiliado', str_contains($frontController, "'/afiliado/login' : '/login'"));
$check('26 chave PIX respeita o tipo', AffiliateValidator::pixKeyIsValid('email', 'afiliado@example.com') && !AffiliateValidator::pixKeyIsValid('email', 'invalida'));

echo 'Total: ' . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
