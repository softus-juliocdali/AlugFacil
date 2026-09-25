<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class ReservationAuthorization
{
    public function __construct(private ?PDO $db=null) {$this->db??=Database::getConnection();}

    public function capabilities(int $uid): array
    {
        $q=$this->db->prepare('SELECT status,tipo_usuario FROM usuarios WHERE id=:u');$q->execute(['u'=>$uid]);$u=$q->fetch();
        if(!$u||$u['status']!=='ativo')return [];
        return in_array($u['tipo_usuario'],['cliente','proprietario','admin'],true)?['reserva.criar','reserva.ver_proprias','reserva.pagar_proprias','reserva.cancelar_proprias']:[];
    }

    public function assertCanBook(int $uid,int $propertyId): void
    {
        if(!in_array('reserva.criar',$this->capabilities($uid),true))throw new DomainException('Identidade sem permissao para reservar.');
        $q=$this->db->prepare('SELECT p.usuario_id FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id WHERE c.id=:c');$q->execute(['c'=>$propertyId]);$owner=$q->fetchColumn();
        if($owner===false)throw new DomainException('Imovel indisponivel.');
        if((int)$owner===$uid)throw new DomainException('Voce nao pode reservar seu proprio imovel.');
    }

    public function assertReservation(int $uid,int $reservationId,string $capability): void
    {
        if(!in_array($capability,$this->capabilities($uid),true))throw new DomainException('Acao nao autorizada.');
        $q=$this->db->prepare('SELECT chacara_id FROM reservas WHERE id=:r AND usuario_id=:u');$q->execute(['r'=>$reservationId,'u'=>$uid]);$cid=$q->fetchColumn();
        if($cid===false)throw new DomainException('Reserva indisponivel para esta identidade.');
        if($capability==='reserva.pagar_proprias')$this->assertCanBook($uid,(int)$cid);
    }
}
