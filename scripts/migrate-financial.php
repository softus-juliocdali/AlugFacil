<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true)||getenv('DB_NAME')!=='alugfacil_dev') throw new RuntimeException('Somente alugfacil_dev local.');
$db=App\Core\Database::getConnection();
$phase=(int)($argv[1]??0);
$phases=[2=>['20260910_phase2_identity.sql','20260910-phase1-pricing-origin'],3=>['20260910_phase3_operations.sql','20260910_phase2_identity'],31=>['20260910_phase3_vault.sql','20260910_phase3_operations'],4=>['20260910_phase4_commercial.sql','20260910_phase3_vault'],41=>['20260910_phase4_affiliate_snapshots.sql','20260910_phase4_commercial'],42=>['20260910_phase4_snapshot_completion.sql','20260910_phase4_affiliate_snapshots'],5=>['20260910_phase5_property_payments.sql','20260910_phase4_snapshot_completion'],6=>['20260910_phase6_frozen_quotes.sql','20260910_phase5_property_payments'],61=>['20260910_phase6_payout_snapshot.sql','20260910_phase6_frozen_quotes'],62=>['20260910_phase6_checkout_clock.sql','20260910_phase6_payout_snapshot'],7=>['20260910_phase7_agenda.sql','20260910_phase6_checkout_clock'],71=>['20260910_phase7_settlement.sql','20260910_phase7_agenda'],72=>['20260910_phase7_refund_ledger.sql','20260910_phase7_settlement'],8=>['20260910_phase8_installment_schedule.sql','20260910_phase7_refund_ledger'],81=>['20260910_phase8_integrity.sql','20260910_phase8_installment_schedule'],82=>['20260910_phase8_payout_projection.sql','20260910_phase8_integrity'],83=>['20260910_phase8_payment_identity.sql','20260910_phase8_payout_projection'],9=>['20260910_phase9_delinquency.sql','20260910_phase8_payment_identity']];
$phases[91]=['20260916_phase9_entry_deadline.sql','20260910_phase9_delinquency'];
$phases[92]=['20260916_monthly_refund_hold.sql','20260916_phase9_entry_deadline'];
$phases[93]=['20260917_financial_close.sql','20260916_monthly_refund_hold'];
if(!isset($phases[$phase])) throw new RuntimeException('Fase nao implementada neste runner.');
[$filename,$dependency]=$phases[$phase];$file=APP_ROOT.'/database/'.$filename;$version=pathinfo($filename,PATHINFO_FILENAME);$hash=hash_file('sha256',$file);
$q=$db->prepare('SELECT sha256 FROM financeiro_schema_versions WHERE versao=:v');$q->execute(['v'=>$version]);$old=$q->fetchColumn();
if($old!==false){if(!hash_equals($old,$hash))throw new RuntimeException('Checksum aplicado diverge.');echo "Fase ja aplicada.\n";exit;}
$q->execute(['v'=>$dependency]);if(!$q->fetchColumn())throw new RuntimeException('Fase anterior obrigatoria.');
if(!in_array('--apply',$argv,true)){echo "Pronto para aplicar fase $phase com --apply.\n";exit;}
$backups=glob(APP_ROOT.'/storage/financial-migration/alugfacil_dev-*.dump');rsort($backups);$backup=$backups[0]??'';
if(!$backup||time()-filemtime($backup)>86400||!is_file($backup.'.sha256')||!hash_equals(trim(file_get_contents($backup.'.sha256')),hash_file('sha256',$backup)))throw new RuntimeException('Backup recente verificado obrigatorio.');
$db->beginTransaction();try{$db->exec("SET LOCAL lock_timeout='10s'; SET LOCAL statement_timeout='120s'");$db->exec(file_get_contents($file));$db->prepare('INSERT INTO financeiro_schema_versions(versao,sha256) VALUES(:v,:h)')->execute(['v'=>$version,'h'=>$hash]);$db->commit();echo "Fase $phase aplicada localmente.\n";}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
