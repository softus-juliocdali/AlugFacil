<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use RuntimeException;
/** All business actions lock the property first, then the reservation, then read the clock. */
final class ReservationLock
{
 public static function acquire(PDO $db,int $id):array
 {
  if(!$db->inTransaction())throw new RuntimeException('Lock de reserva exige transacao.');
  $q=$db->prepare('SELECT chacara_id FROM reservas WHERE id=:id');$q->execute(['id'=>$id]);$property=$q->fetchColumn();if($property===false)throw new RuntimeException('Reserva nao encontrada.');
  $db->prepare('SELECT pg_advisory_xact_lock(:c)')->execute(['c'=>$property]);
  $q=$db->prepare('SELECT * FROM reservas WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Reserva nao encontrada.');
  $r['_agora']=$db->query('SELECT clock_timestamp()')->fetchColumn();return $r;
 }
}
