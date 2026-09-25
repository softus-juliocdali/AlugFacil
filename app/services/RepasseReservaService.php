<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use PDO;use RuntimeException;
final class RepasseReservaService
{
 public function __construct(private AsaasTransferClientInterface $client,private ?PDO $db=null){$this->db??=Database::getConnection();}
 public function processar(int $id,bool $confirmado=false):array
 {
  $q=$this->db->prepare('SELECT o.id FROM repasses_reservas rp JOIN obrigacoes_reserva o ON o.reserva_id=rp.reserva_id AND o.numero=0 JOIN reservas r ON r.id=rp.reserva_id WHERE rp.id=:id AND r.schema_financeiro=2 AND r.modalidade=\'integral\'');$q->execute(['id'=>$id]);$obligation=$q->fetchColumn();if($obligation===false)throw new RuntimeException('Repasse legado exige conciliacao antes de migrar para obrigacao.');
  if(!$confirmado)return ['dry_run'=>true,'obrigacao_id'=>(int)$obligation];
  return (new ReservationSettlementService($this->db,$this->client))->payout((int)$obligation);
 }
}
