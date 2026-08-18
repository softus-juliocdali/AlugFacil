<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ReservaStatusService
{
    public const BLOQUEIAM_DATAS = ['aguardando_pagamento', 'pagamento_confirmado', 'confirmada', 'em_andamento', 'cancelamento_solicitado', 'disputa'];
    public const FINAIS = ['finalizada', 'cancelada', 'expirada', 'estornada'];

    public static function listaSqlBloqueiamDatas(): string
    {
        return "'" . implode("','", self::BLOQUEIAM_DATAS) . "'";
    }

    private const TRANSICOES = [
        'solicitada' => ['aguardando_pagamento'],
        'aguardando_pagamento' => ['confirmada', 'expirada', 'cancelada'],
        'pagamento_confirmado' => ['confirmada'],
        'confirmada' => ['em_andamento', 'cancelamento_solicitado', 'cancelada', 'finalizada'],
        'em_andamento' => ['finalizada', 'cancelamento_solicitado'],
        'cancelamento_solicitado' => ['cancelada', 'confirmada', 'em_andamento'],
        'cancelada' => ['estornada'],
    ];

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= Database::getConnection();
    }

    public static function podeTransicionar(string $atual, string $novo): bool
    {
        return in_array($novo, self::TRANSICOES[$atual] ?? [], true);
    }

    public function transicionar(int $reservaId, string $novo, array $contexto = []): array
    {
        $iniciou = !$this->db->inTransaction();
        if ($iniciou) $this->db->beginTransaction();
        try {
            $reserva = $this->buscarBloqueada($reservaId);
            if (!$reserva) throw new RuntimeException('Reserva nao encontrada.');
            $atual = (string) $reserva['status_reserva'];
            if ($atual === $novo) {
                if ($iniciou) $this->db->commit();
                return $reserva;
            }
            if (!self::podeTransicionar($atual, $novo)) throw new RuntimeException("Transicao de {$atual} para {$novo} nao permitida.");
            if ($novo === 'expirada' && $this->possuiPagamentoConfirmado($reservaId, $reserva)) throw new RuntimeException('Reserva paga nao pode expirar.');

            $campos = ['status_reserva = :novo', 'ultima_transicao_em = CURRENT_TIMESTAMP', 'versao_status = versao_status + 1'];
            if ($novo === 'cancelamento_solicitado') $campos[] = 'cancelamento_solicitado_em = CURRENT_TIMESTAMP';
            if ($novo === 'cancelada') $campos[] = 'cancelada_em = CURRENT_TIMESTAMP';
            if ($novo === 'em_andamento') $campos[] = 'inicio_real_em = CURRENT_TIMESTAMP';
            if ($novo === 'finalizada') $campos[] = 'finalizada_em = CURRENT_TIMESTAMP';
            $this->db->prepare('UPDATE reservas SET '.implode(', ', $campos).' WHERE id = :id')->execute(['novo' => $novo, 'id' => $reservaId]);

            $this->db->prepare('INSERT INTO historico_status_reservas (reserva_id,status_anterior,status_novo,motivo,origem,responsavel_tipo,responsavel_id,metadados) VALUES (:id,:anterior,:novo,:motivo,:origem,:tipo,:responsavel,:metadados)')->execute([
                'id'=>$reservaId, 'anterior'=>$atual, 'novo'=>$novo,
                'motivo'=>$this->limitar($contexto['motivo'] ?? null),
                'origem'=>$contexto['origem'] ?? 'sistema', 'tipo'=>$contexto['responsavel_tipo'] ?? 'sistema',
                'responsavel'=>$contexto['responsavel_id'] ?? null,
                'metadados'=>isset($contexto['metadados']) ? json_encode($contexto['metadados'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            if ($iniciou) $this->db->commit();
            return array_merge($reserva, ['status_reserva'=>$novo]);
        } catch (Throwable $e) {
            if ($iniciou && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function registrarInicial(int $reservaId, string $status = 'aguardando_pagamento'): void
    {
        $this->db->prepare("INSERT INTO historico_status_reservas (reserva_id,status_anterior,status_novo,motivo,origem,responsavel_tipo) SELECT :id,NULL,:status,'Reserva criada','sistema','sistema' WHERE NOT EXISTS (SELECT 1 FROM historico_status_reservas WHERE reserva_id=:id)")->execute(['id'=>$reservaId,'status'=>$status]);
    }

    public function expirarVencidas(int $limite = 100, ?int $chacaraId = null): int
    {
        $sql = "SELECT r.id FROM reservas r WHERE r.status_reserva='aguardando_pagamento' AND r.expira_em IS NOT NULL AND r.expira_em<=CURRENT_TIMESTAMP AND r.status_pagamento<>'pago' AND NOT EXISTS (SELECT 1 FROM pagamentos p WHERE p.reserva_id=r.id AND p.status_pagamento='pago')";
        $params = [];
        if ($chacaraId !== null) { $sql .= ' AND r.chacara_id=:chacara'; $params['chacara']=$chacaraId; }
        $sql .= ' ORDER BY r.expira_em,r.id LIMIT :limite FOR UPDATE SKIP LOCKED';
        $stmt=$this->db->prepare($sql); foreach($params as $k=>$v) $stmt->bindValue(':'.$k,$v,PDO::PARAM_INT); $stmt->bindValue(':limite',max(1,min($limite,500)),PDO::PARAM_INT);
        $iniciou=!$this->db->inTransaction(); if($iniciou)$this->db->beginTransaction();
        try { $stmt->execute(); $ids=$stmt->fetchAll(PDO::FETCH_COLUMN); foreach($ids as $id) $this->transicionar((int)$id,'expirada',['motivo'=>'Prazo de pagamento encerrado','origem'=>'sistema']); if($iniciou)$this->db->commit(); return count($ids); }
        catch(Throwable $e){ if($iniciou&&$this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    private function buscarBloqueada(int $id): ?array { $s=$this->db->prepare('SELECT * FROM reservas WHERE id=:id FOR UPDATE');$s->execute(['id'=>$id]);return $s->fetch()?:null; }
    private function possuiPagamentoConfirmado(int $id,array $r): bool { if(($r['status_pagamento']??'')==='pago')return true;$s=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM pagamentos WHERE reserva_id=:id AND status_pagamento='pago')");$s->execute(['id'=>$id]);return(bool)$s->fetchColumn(); }
    private function limitar(mixed $v): ?string { $v=trim((string)$v);return $v===''?null:mb_substr($v,0,500); }
}
