<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Executor local apenas.');
App\Services\AsaasEnvironment::assertCredentials(true);
$db=App\Core\Database::getConnection();
$pid=(int)($argv[1]??0);
$ids=$pid>0?[$pid]:$db->query("SELECT proprietario_id FROM onboarding_fila WHERE estado IN ('pendente','aguardando_aceite','aguardando_asaas','conciliacao_manual') AND proxima_tentativa_em<=clock_timestamp() ORDER BY proxima_tentativa_em LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
$service=new App\Services\ProvisionamentoAsaasService(new App\Services\AsaasHttpClient());
foreach($ids as $id){try{$r=$service->processar((int)$id);echo json_encode(['proprietario_id'=>(int)$id,'status'=>$r['status_local']]).PHP_EOL;}catch(Throwable $e){echo json_encode(['proprietario_id'=>(int)$id,'resultado'=>'pendencia_registrada','classe'=>get_class($e)]).PHP_EOL;}}
