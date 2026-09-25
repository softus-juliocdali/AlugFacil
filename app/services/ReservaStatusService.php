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
        'em_andamento' => ['finalizada', 'cancelamento_solicitado', 'cancelada'],
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
        $sql = "SELECT r.id FROM reservas r WHERE r.status_reserva='aguardando_pagamento' AND r.expira_em<=clock_timestamp() AND r.status_pagamento<>'pago'";
        $params=[];if($chacaraId!==null){$sql.=' AND r.chacara_id=:c';$params['c']=$chacaraId;}
        $sql.=' ORDER BY r.chacara_id,r.id LIMIT '.max(1,min($limite,500));$stmt=$this->db->prepare($sql);$stmt->execute($params);$ids=$stmt->fetchAll(PDO::FETCH_COLUMN);$count=0;
        foreach($ids as $id){$own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{
            $r=ReservationLock::acquire($this->db,(int)$id);
            if($r['status_reserva']==='aguardando_pagamento'&&new \DateTimeImmutable($r['expira_em'])<=new \DateTimeImmutable($r['_agora'])&&!$this->possuiPagamentoConfirmado((int)$id,$r)){
                $this->transicionar((int)$id,'expirada',['motivo'=>'Prazo de pagamento encerrado','origem'=>'sistema']);
                $this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave) SELECT reserva_id,id,'cancelar_cobranca','cancelar:'||id FROM obrigacoes_reserva WHERE reserva_id=:r AND estado='pendente' ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$id]);
                $this->db->prepare("UPDATE obrigacoes_reserva SET estado='cancelada' WHERE reserva_id=:r AND estado='pendente'")->execute(['r'=>$id]);$count++;
            }
            if($own)$this->db->commit();
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}}
        return $count;
    }

    private function buscarBloqueada(int $id): ?array { return ReservationLock::acquire($this->db,$id); }
    private function possuiPagamentoConfirmado(int $id,array $r): bool { if(($r['status_pagamento']??'')==='pago')return true;$s=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM pagamentos WHERE reserva_id=:id AND status_pagamento='pago')");$s->execute(['id'=>$id]);return(bool)$s->fetchColumn(); }
    private function limitar(mixed $v): ?string { $v=trim((string)$v);return $v===''?null:mb_substr($v,0,500); }
}
