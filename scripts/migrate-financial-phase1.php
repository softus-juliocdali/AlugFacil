<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use App\Core\Database;
use App\Services\CadastroBackfillService;

if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true) || getenv('DB_NAME')!=='alugfacil_dev') throw new RuntimeException('Migration restrita ao banco local alugfacil_dev.');
$db=Database::getConnection();
if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev') throw new RuntimeException('Banco inesperado.');
$extra=in_array('--pricing-origin',$argv,true);
$file=APP_ROOT.'/database/'.($extra?'20260910_phase1_pricing_origin.sql':'20260910_phase1_canonical_schema.sql');$hash=hash_file('sha256',$file);$version=$extra?'20260910-phase1-pricing-origin':'20260910-phase1';
if($db->query("SELECT to_regclass('public.financeiro_schema_versions')")->fetchColumn()) {
    $s=$db->prepare('SELECT sha256 FROM financeiro_schema_versions WHERE versao=:v');$s->execute(['v'=>$version]);$old=$s->fetchColumn();
    if($old!==false) {if(!hash_equals($old,$hash)) throw new RuntimeException('Migration aplicada com checksum diferente.'); echo "Fase 1 ja aplicada; nenhum backfill repetido.\n";exit;}
}
if(!in_array('--apply',$argv,true)) {echo "Use --apply somente depois do preflight/backup.\n";exit;}
$backups=glob(APP_ROOT.'/storage/financial-migration/alugfacil_dev-*.dump');rsort($backups);$backup=$backups[0]??'';
if(!$backup || !is_file($backup.'.sha256') || !hash_equals(trim(file_get_contents($backup.'.sha256')),hash_file('sha256',$backup))) throw new RuntimeException('Backup local verificado obrigatorio.');
if(time()-filemtime($backup)>86400) throw new RuntimeException('Backup anterior a 24 horas; refaca antes de migrar.');
$db->beginTransaction();
try {
    $db->exec("SET LOCAL lock_timeout='10s'; SET LOCAL statement_timeout='120s'");
    $db->exec('LOCK TABLE proprietarios,usuarios,proprietario_dados_financeiros IN SHARE ROW EXCLUSIVE MODE');
    $db->exec(file_get_contents($file));
    $report=$extra?[]:(new CadastroBackfillService())->executar($db);
    $db->prepare('INSERT INTO financeiro_schema_versions(versao,sha256) VALUES(:v,:h)')->execute(['v'=>$version,'h'=>$hash]);
    if(!$extra && file_put_contents(APP_ROOT.'/storage/financial-migration/conflitos-phase1.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Falha ao gravar relatorio de conflitos.');
    $db->commit();echo 'Fase 1 aplicada; conflitos preservados: '.count($report).PHP_EOL;
} catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
