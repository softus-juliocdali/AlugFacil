<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use DateTimeImmutable;use PDO;use RuntimeException;
final class InstallmentScheduleService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 /** Caller holds the reservation lock in the same transaction as entry confirmation. */
 public function activate(array $r,DateTimeImmutable $paidAt):void
 {
  if(!$this->db->inTransaction()||$r['modalidade']!=='entrada_parcelamento')throw new RuntimeException('Ativacao exige reserva parcelada bloqueada.');
  $q=$this->db->prepare('SELECT * FROM cronogramas_reserva WHERE reserva_id=:r');$q->execute(['r'=>$r['id']]);if($q->fetch())return;
  $snapshot=json_decode($r['precificacao_detalhes'],true,512,JSON_THROW_ON_ERROR);$schedule=ReservationPricingEngine::finalSchedule($snapshot,$paidAt);
  $this->db->prepare('INSERT INTO cronogramas_reserva(reserva_id,referencia_entrada_em,limite_ultima_parcela,quantidade_parcelas,regra) VALUES(:r,:p,:limit,:n,:rule)')->execute(['r'=>$r['id'],'p'=>$paidAt->format(DATE_ATOM),'limit'=>$snapshot['limite_ultima_parcela'],'n'=>$snapshot['quantidade_parcelas_saldo'],'rule'=>$snapshot['calendario_regra']]);
  foreach($schedule as $p){if($p['numero']===0)continue;$q=$this->db->prepare("INSERT INTO obrigacoes_reserva(reserva_id,numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,vencimento_em,cancelamento_em,forma_pagamento) VALUES(:r,:n,'parcela',:h,:o,:c,:p,:t,:due,:cancel,:method) RETURNING id");$q->execute(['r'=>$r['id'],'n'=>$p['numero'],'h'=>$p['hospedagem_centavos'],'o'=>$p['taxa_operacional_centavos'],'c'=>$p['comissao_centavos'],'p'=>$p['proprietario_centavos'],'t'=>$p['total_centavos'],'due'=>$p['vencimento_em'],'cancel'=>$p['cancelamento_em'],'method'=>$r['forma_pagamento']]);$id=(int)$q->fetchColumn();
   $this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave,proxima_tentativa_em) VALUES(:r,:o,'emitir_parcela',:k,:when) ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$r['id'],'o'=>$id,'k'=>'emitir:'.$id,'when'=>$r['forma_pagamento']==='CREDIT_CARD'?$p['vencimento_em']:$r['_agora']]);
  }
  $this->db->prepare('UPDATE reservas SET entrada_confirmada_em=COALESCE(entrada_confirmada_em,:paid) WHERE id=:r')->execute(['paid'=>$paidAt->format(DATE_ATOM),'r'=>$r['id']]);
 }
 public function obligations(int $reservation,int $user):array
 {
  (new ReservationAuthorization($this->db))->assertReservation($user,$reservation,'reserva.ver_proprias');
  $q=$this->db->prepare('SELECT limite_ultima_parcela FROM cronogramas_reserva WHERE reserva_id=:r');$q->execute(['r'=>$reservation]);$limit=$q->fetchColumn();$cutoff=$limit?new DateTimeImmutable($limit):null;
  $q=$this->db->prepare('SELECT numero,tipo,hospedagem_centavos,taxa_operacional_centavos,comissao_centavos,proprietario_centavos,total_centavos,pago_centavos,estado,vencimento_em,cancelamento_em,invoice_url,forma_pagamento FROM obrigacoes_reserva WHERE reserva_id=:r ORDER BY numero');$q->execute(['r'=>$reservation]);$rows=$q->fetchAll();
  $now=new DateTimeImmutable($this->db->query('SELECT clock_timestamp()')->fetchColumn());$last=count($rows)-1;
  foreach($rows as $index=>&$row){$row['situacao']=$row['estado'];if($row['estado']==='pendente'&&$row['numero']>0&&$row['cancelamento_em']!==null){$row['situacao']=InstallmentDelinquencyService::stage($row,$row['numero']===$last,$now,$cutoff,isset($rows[$index+1])?new DateTimeImmutable($rows[$index+1]['vencimento_em']):null);if($cutoff)$row['cancelamento_em']=InstallmentDelinquencyService::deadline($row,$cutoff)->format(DATE_ATOM);}}
  unset($row);return $rows;
 }
}
