<?php
declare(strict_types=1);
namespace FinancialRelease;
use PDO;
use RuntimeException;

/** Frozen operational mode. Queued monetary work is reported, never claimed or retried. */
final class Worker
{
    public static function run(PDO $db,array $manifest,array $certificate): array
    {
        if(($certificate['kind']??'')!=='financial-rehearsal-v1'||$certificate['manifest_digest']!==Runtime::hash($manifest))throw new RuntimeException('Worker requires the approved rehearsal certificate');
        $db->exec('BEGIN READ ONLY');
        try {
            if(!$db->query('SELECT pg_try_advisory_xact_lock(741075,23)')->fetchColumn())throw new RuntimeException('Financial release/worker already running');
            if(Catalog::versions($db,$manifest)!==23||Catalog::schema($db)!==$certificate['stages'][23])throw new RuntimeException('Worker schema/version drift');
            $queries=[
                'reservation_tasks'=>"SELECT tipo,estado,count(*) AS count,min(criado_em) AS oldest FROM tarefas_financeiras_reserva WHERE estado NOT IN ('concluida') GROUP BY tipo,estado ORDER BY tipo,estado",
                'onboarding'=>"SELECT estado,count(*) AS count FROM onboarding_fila WHERE estado<>'concluido' GROUP BY estado ORDER BY estado",
                'webhooks'=>"SELECT status_processamento,count(*) AS count,min(recebido_em) AS oldest FROM asaas_webhook_eventos WHERE status_processamento IN ('recebido','erro','processando') GROUP BY status_processamento ORDER BY status_processamento",
                'operations'=>"SELECT tipo,estado,count(*) AS count FROM operacoes_financeiras WHERE estado IN ('pendente','executando','desconhecida') GROUP BY tipo,estado ORDER BY tipo,estado",
                'monthly_refunds'=>"SELECT refund_estado,count(*) AS count FROM cobrancas_mensalidades WHERE refund_bloqueado GROUP BY refund_estado ORDER BY refund_estado",
            ];
            $queues=[];foreach($queries as $key=>$sql)$queues[$key]=$db->query($sql)->fetchAll();
            $db->rollBack();
            return ['mode'=>'frozen','external_mutations'=>false,'database_writes'=>false,'webhook_homologated'=>false,'transfers_homologated'=>false,'queues'=>$queues,'action'=>'Reconcile webhook interruption before enabling deadlines or monetary jobs'];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
