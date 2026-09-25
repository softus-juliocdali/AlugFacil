<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class AsaasWebhookEvento
{
    public static function validEventId(mixed $id): bool
    { return is_string($id) && preg_match('/^[a-zA-Z0-9_&-]{1,120}$/D', $id) === 1; }

    public function __construct(private ?PDO $db = null) { $this->db ??= Database::getConnection(); }

    public function receber(array $payload, string $canonicalJson): array
    {
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $hash = hash('sha256', $canonicalJson);
        $sql = <<<'SQL'
            INSERT INTO asaas_webhook_eventos
              (asaas_event_id,tipo_evento,tipo_recurso,asaas_payment_id,external_reference,payload,payload_hash)
            VALUES (:event_id,:tipo,:recurso,:payment_id,:external_reference,CAST(:payload AS jsonb),:hash)
            ON CONFLICT (asaas_event_id) DO NOTHING RETURNING id,status_processamento,payload_hash
            SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'event_id'=>(string)$payload['id'], 'tipo'=>(string)$payload['event'],
            'recurso'=>isset($payload['payment'])?'payment':(isset($payload['transfer'])?'transfer':'unknown'),
            'payment_id'=>$payment['id'] ?? null, 'external_reference'=>$payment['externalReference'] ?? null,
            'payload'=>json_encode(\App\Services\FinancialPayloadFilter::sanitize($payload),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), 'hash'=>$hash,
        ]);
        $created = $stmt->fetch();
        if ($created) return ['created'=>true,'divergent'=>false,'event'=>$created];

        $existing = $this->buscarPorEventId((string)$payload['id'], true);
        if (!$existing) throw new \RuntimeException('Falha ao recuperar evento concorrente.');
        $divergent = !hash_equals((string)$existing['payload_hash'], $hash);
        if ($divergent) {
            $u=$this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento='divergente',ultimo_erro='Mesmo ID de evento recebido com payload diferente.',atualizado_em=CURRENT_TIMESTAMP WHERE id=:id");
            $u->execute(['id'=>$existing['id']]);
        }
        return ['created'=>false,'divergent'=>$divergent,'event'=>$existing];
    }

    public function buscarPorEventId(string $id, bool $lock=false): ?array
    { $s=$this->db->prepare('SELECT * FROM asaas_webhook_eventos WHERE asaas_event_id=:id'.($lock?' FOR UPDATE':''));$s->execute(['id'=>$id]);return $s->fetch()?:null; }

    public function listar(array $filtros): array
    {
        $where=[];$params=[];
        foreach(['status_processamento'=>'status','tipo_evento'=>'tipo','asaas_payment_id'=>'payment'] as $col=>$key){if(($filtros[$key]??'')!==''){$where[]="$col = :$key";$params[$key]=$filtros[$key];}}
        if(($filtros['data']??'')!==''){$where[]='recebido_em::date = :data';$params['data']=$filtros['data'];}
        $sql='SELECT id,asaas_event_id,tipo_evento,asaas_payment_id,external_reference,status_processamento,quantidade_tentativas,ultimo_erro,recebido_em,processado_em FROM asaas_webhook_eventos'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY recebido_em DESC,id DESC LIMIT 200';
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    public function detalhar(int $id): ?array
    {
        $s=$this->db->prepare("SELECT e.*,r.id AS reserva_id,r.status_reserva,r.status_pagamento,p.id AS pagamento_id FROM asaas_webhook_eventos e LEFT JOIN pagamentos p ON p.id_transacao_asaas=e.asaas_payment_id LEFT JOIN reservas r ON r.id=COALESCE(p.reserva_id,CASE WHEN e.external_reference ~ '^reserva_[0-9]+$' THEN substring(e.external_reference from '[0-9]+')::integer END) WHERE e.id=:id LIMIT 1");
        $s->execute(['id'=>$id]);return $s->fetch()?:null;
    }

    public function solicitarReprocessamento(int $id, int $adminId, string $motivo): bool
    {
        $s=$this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento='recebido',proxima_tentativa_em=NULL,ultimo_erro=NULL,revisao_motivo=:motivo,revisao_por=:admin,revisao_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND status_processamento IN ('erro','divergente','ignorado')");
        $s->execute(['id'=>$id,'admin'=>$adminId,'motivo'=>mb_substr(trim($motivo),0,500)]);return $s->rowCount()===1;
    }

    public function marcarRevisao(int $id,int $adminId,string $motivo): bool
    { $s=$this->db->prepare("UPDATE asaas_webhook_eventos SET status_processamento='divergente',revisao_motivo=:motivo,revisao_por=:admin,revisao_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id");$s->execute(['id'=>$id,'admin'=>$adminId,'motivo'=>mb_substr(trim($motivo),0,500)]);return $s->rowCount()===1; }
}
