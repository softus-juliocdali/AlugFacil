<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use PDO;use RuntimeException;use Throwable;
final class ExceptionalReservationCancellationService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public function cancel(int $reservation,int $admin,string $reason,string $policy):void
 {
  $reason=trim($reason);$policy=trim($policy);if(mb_strlen($reason)<10||mb_strlen($policy)<10)throw new RuntimeException('Informe motivo e politica financeira da excecao, com pelo menos 10 caracteres cada.');
  $this->db->beginTransaction();try{
   $q=$this->db->prepare("SELECT id FROM usuarios WHERE id=:id AND tipo_usuario='admin' AND status='ativo' FOR SHARE");$q->execute(['id'=>$admin]);if(!$q->fetchColumn())throw new RuntimeException('Excecao exige administrador ativo.');
   $r=ReservationLock::acquire($this->db,$reservation);if($r['schema_financeiro']!==2)throw new RuntimeException('Reserva legada exige conciliacao especifica.');
   if($r['status_reserva']==='cancelada'&&$r['tipo_cancelamento']==='administrativo_excepcional'){$this->db->commit();return;}
   $q=$this->db->prepare("SELECT COALESCE(SUM(o.pago_centavos),0) recebido,COALESCE(SUM(CASE WHEN p.estado='concluido' THEN p.valor_centavos ELSE 0 END),0) repassado FROM obrigacoes_reserva o LEFT JOIN repasses_obrigacoes p ON p.obrigacao_id=o.id WHERE o.reserva_id=:r");$q->execute(['r'=>$reservation]);$totals=$q->fetch();
   $this->db->prepare('INSERT INTO cancelamentos_administrativos_reserva(reserva_id,administrador_id,motivo,politica_financeira,recebido_centavos,repassado_centavos) VALUES(:r,:a,:m,:p,:received,:transferred)')->execute(['r'=>$reservation,'a'=>$admin,'m'=>$reason,'p'=>$policy,'received'=>$totals['recebido'],'transferred'=>$totals['repassado']]);
   $this->db->prepare("UPDATE reservas SET tipo_cancelamento='administrativo_excepcional',divergencia_pagamento=TRUE WHERE id=:r")->execute(['r'=>$reservation]);
   (new ReservaStatusService($this->db))->transicionar($reservation,'cancelada',['motivo'=>$reason,'origem'=>'administrador','responsavel_tipo'=>'administrador','responsavel_id'=>$admin,'metadados'=>['politica_financeira'=>$policy,'execucao_financeira'=>'conciliacao_administrativa_sem_estorno_automatico']]);
   $this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave) SELECT reserva_id,id,'cancelar_cobranca','cancelar:'||id FROM obrigacoes_reserva WHERE reserva_id=:r AND estado='pendente' ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$reservation]);
   $this->db->prepare("UPDATE obrigacoes_reserva SET estado='cancelada' WHERE reserva_id=:r AND estado='pendente'")->execute(['r'=>$reservation]);
   $this->db->prepare("UPDATE autorizacoes_pagamento_reserva SET estado='revogada',revogado_em=COALESCE(revogado_em,clock_timestamp()),segredo_cifrado=NULL,nonce=NULL,tag=NULL WHERE reserva_id=:r")->execute(['r'=>$reservation]);
   $this->db->prepare("UPDATE repasses_obrigacoes SET estado='manual' WHERE obrigacao_id IN (SELECT id FROM obrigacoes_reserva WHERE reserva_id=:r) AND estado<>'concluido'")->execute(['r'=>$reservation]);
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
