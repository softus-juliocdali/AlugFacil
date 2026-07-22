<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require __DIR__.'/bootstrap.php';
use App\Core\Database;use App\Services\AsaasWebhookService;
$options=getopt('',['limit::','event-id::','retry-errors','dry-run']);
$cfg=require APP_ROOT.'/app/config/apis.php';$limit=max(1,min(500,(int)($options['limit']??$cfg['asaas']['webhook_process_limit'])));$retry=array_key_exists('retry-errors',$options);$dry=array_key_exists('dry-run',$options);
$db=Database::getConnection();if(config('app_env')==='development'&&$db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev'){fwrite(STDERR,"Banco local nao permitido.\n");exit(2);}
$params=[];$where="status_processamento='recebido'";if($retry)$where="(status_processamento='recebido' OR (status_processamento='erro' AND quantidade_tentativas<4))";
if(isset($options['event-id'])){$where.=' AND asaas_event_id=:event';$params['event']=(string)$options['event-id'];}
$sql="SELECT id FROM asaas_webhook_eventos WHERE $where AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em<=CURRENT_TIMESTAMP) ORDER BY recebido_em,id LIMIT :limit";$s=$db->prepare($sql);foreach($params as$k=>$v)$s->bindValue(':'.$k,$v);$s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();$ids=$s->fetchAll(PDO::FETCH_COLUMN);
$service=new AsaasWebhookService($db);$failed=0;foreach($ids as$id){$result=$service->processarPorId((int)$id,$retry,$dry);echo "Evento interno $id: $result\n";if($result==='erro')$failed++;}echo 'Total selecionado: '.count($ids).PHP_EOL;exit($failed?1:0);
