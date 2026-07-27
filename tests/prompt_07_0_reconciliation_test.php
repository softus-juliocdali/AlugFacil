<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;use App\Services\FakeAsaasClient;
$db=Database::getConnection();$root=dirname(__DIR__);$checks=[];$ok=function(string$n,bool$v)use(&$checks){$checks[$n]=$v;};
$before2=$db->query("SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=2")->fetchColumn();$before5=$db->query("SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=5")->fetchColumn();
$service=(string)file_get_contents($root.'/app/services/AsaasSubcontaReconciliationService.php');$script=(string)file_get_contents($root.'/scripts/reconcile-asaas-subaccount.php');$fake=new FakeAsaasClient();
$ok('01_banco_dev',$db->query('SELECT current_database()')->fetchColumn()==='alugfacil_dev');
$ok('02_reconciliacao_disponivel',str_contains($service,'reconciliarExistente'));
$ok('03_consulta_antes_criacao',str_contains($service,'consultarCorrespondencias'));
$ok('04_multiplas_bloqueiam',str_contains($service,'Multiplas subcontas'));
$ok('05_timeout_nao_repete',str_contains((string)file_get_contents($root.'/app/services/AsaasSubcontaService.php'),'nova criacao bloqueada'));
$cols=$db->query("SELECT column_name FROM information_schema.columns WHERE table_name='asaas_subcontas'")->fetchAll(PDO::FETCH_COLUMN);
$ok('06_api_key_nao_persistida',!in_array('api_key',$cols,true));$ok('07_script_dry_run_padrao',str_contains($script,'DRY-RUN LOCAL'));$ok('08_sem_servico_split',!str_contains($script,'AsaasSplitService'));$ok('09_sem_cobranca_real',$fake->cobrancasCriadas===0);$ok('10_sem_transferencia_real',$fake->transferenciasCriadas===0);
$after2=$db->query("SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=2")->fetchColumn();$after5=$db->query("SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=5")->fetchColumn();$ok('11_reservas_2_5_intactas',$before2===$after2&&$before5===$after5);
$fail=0;foreach($checks as$n=>$v){echo($v?'[OK] ':'[FALHA] ').$n.PHP_EOL;if(!$v)$fail++;}echo'TOTAL='.count($checks).' FALHAS='.$fail.PHP_EOL;exit($fail?1:0);
