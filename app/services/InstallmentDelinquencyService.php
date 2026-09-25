<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use DateTimeImmutable;use PDO;use Throwable;
final class InstallmentDelinquencyService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public static function deadline(array $o,DateTimeImmutable $cutoff):DateTimeImmutable
 {return min(new DateTimeImmutable($o['cancelamento_em']),$cutoff);}
 public static function stage(array $o,bool $last,DateTimeImmutable $now,?DateTimeImmutable $cutoff=null,?DateTimeImmutable $nextDue=null):string
 {
  if((int)$o['pago_centavos']>=(int)$o['total_centavos'])return 'regular';
  $nominal=new DateTimeImmutable($o['cancelamento_em']);$limit=$cutoff===null?$nominal:self::deadline($o,$cutoff);if($now>=$limit)return 'cancelamento_definitivo';
  if(!$last&&$now->getTimestamp()>=($nextDue?->getTimestamp()??($nominal->getTimestamp()-432000)))return 'tolerancia';
  return $now>=new DateTimeImmutable($o['vencimento_em'])?'atrasada':'pendente';
 }
 public function cancelIfDue(int $id):bool
 {
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{
   $r=ReservationLock::acquire($this->db,$id);
   if($r['modalidade']!=='entrada_parcelamento'||$r['entrada_confirmada_em']===null||in_array($r['status_reserva'],ReservaStatusService::FINAIS,true)){if($own)$this->db->commit();return false;}
   $q=$this->db->prepare('SELECT * FROM obrigacoes_reserva WHERE reserva_id=:r AND numero>0 ORDER BY numero FOR UPDATE');$q->execute(['r'=>$id]);$rows=$q->fetchAll();$now=new DateTimeImmutable($this->db->query('SELECT clock_timestamp()')->fetchColumn());$last=count($rows);$cause=null;
   $cutoff=new DateTimeImmutable(json_decode($r['precificacao_detalhes'],true,512,JSON_THROW_ON_ERROR)['limite_ultima_parcela']);
   foreach($rows as $index=>$o){$stage=self::stage($o,$o['numero']===$last,$now,$cutoff,isset($rows[$index+1])?new DateTimeImmutable($rows[$index+1]['vencimento_em']):null);$o['cancelamento_em']=self::deadline($o,$cutoff)->format(DATE_ATOM);
    $this->db->prepare('UPDATE obrigacoes_reserva SET inadimplencia_estado=:s WHERE id=:id')->execute(['s'=>$stage,'id'=>$o['id']]);
    $this->db->prepare('INSERT INTO historico_inadimplencia_reserva(obrigacao_id,reserva_id,estado,pago_centavos,limite_em,observado_em) VALUES(:o,:r,:s,:paid,:limit,:now) ON CONFLICT(obrigacao_id,estado) DO NOTHING')->execute(['o'=>$o['id'],'r'=>$id,'s'=>$stage,'paid'=>$o['pago_centavos'],'limit'=>$o['cancelamento_em'],'now'=>$now->format('Y-m-d H:i:s.uP')]);
    if($stage==='cancelamento_definitivo'&&$o['estado']==='pendente'&&($cause===null||new DateTimeImmutable($o['cancelamento_em'])<new DateTimeImmutable($cause['cancelamento_em'])||($o['numero']===$last&&new DateTimeImmutable($o['cancelamento_em'])==new DateTimeImmutable($cause['cancelamento_em']))))$cause=$o;
   }
   if($cause===null){if($own)$this->db->commit();return false;}
   $q=$this->db->prepare('SELECT COALESCE(SUM(pago_centavos),0) FROM obrigacoes_reserva WHERE reserva_id=:r');$q->execute(['r'=>$id]);$received=(int)$q->fetchColumn();
   $this->db->prepare('INSERT INTO cancelamentos_inadimplencia_reserva(reserva_id,obrigacao_causadora_id,limite_em,decidido_em,ultima_parcela,recebido_centavos) VALUES(:r,:o,:limit,:now,:last,:received) ON CONFLICT(reserva_id) DO NOTHING')->execute(['r'=>$id,'o'=>$cause['id'],'limit'=>$cause['cancelamento_em'],'now'=>$now->format('Y-m-d H:i:s.uP'),'last'=>$cause['numero']===$last,'received'=>$received]);
   $this->db->prepare("UPDATE reservas SET tipo_cancelamento='inadimplencia' WHERE id=:r")->execute(['r'=>$id]);
   (new ReservaStatusService($this->db))->transicionar($id,'cancelada',['motivo'=>'Inadimplencia definitiva da parcela '.$cause['numero'],'origem'=>'sistema','responsavel_tipo'=>'sistema','metadados'=>['obrigacao_id'=>$cause['id'],'limite_em'=>$cause['cancelamento_em'],'politica'=>'sem_estorno']]);
   $this->db->prepare("INSERT INTO tarefas_financeiras_reserva(reserva_id,obrigacao_id,tipo,chave) SELECT reserva_id,id,'cancelar_cobranca','cancelar:'||id FROM obrigacoes_reserva WHERE reserva_id=:r AND estado='pendente' ON CONFLICT(chave) DO NOTHING")->execute(['r'=>$id]);
   $this->db->prepare("UPDATE obrigacoes_reserva SET estado='cancelada' WHERE reserva_id=:r AND estado='pendente'")->execute(['r'=>$id]);
   $this->db->prepare("UPDATE tarefas_financeiras_reserva SET estado='concluida',erro_codigo=NULL WHERE reserva_id=:r AND tipo='emitir_parcela' AND estado IN ('pendente','erro')")->execute(['r'=>$id]);
   $this->db->prepare("UPDATE autorizacoes_pagamento_reserva SET estado='revogada',revogado_em=COALESCE(revogado_em,clock_timestamp()),segredo_cifrado=NULL,nonce=NULL,tag=NULL WHERE reserva_id=:r")->execute(['r'=>$id]);
   $q=$this->db->prepare('SELECT usuario_id FROM proprietarios WHERE id=:p');$q->execute(['p'=>$r['proprietario_id']]);$owner=(int)$q->fetchColumn();
   foreach([$r['usuario_id']=>'/cliente/reserva/'.$id,$owner=>'/proprietario/reservas/'.$id] as $user=>$link)$this->db->prepare("INSERT INTO notificacoes(usuario_id,tipo,titulo,mensagem,link,chave_deduplicacao) VALUES(:u,'reserva_inadimplencia',:title,:message,:link,:key) ON CONFLICT(chave_deduplicacao) DO NOTHING")->execute(['u'=>$user,'title'=>'Reserva #'.$id.' cancelada por inadimplencia','message'=>'A parcela '.$cause['numero'].' permaneceu pendente no limite. A reserva foi cancelada definitivamente, sem estorno dos valores ja pagos. O cancelamento dos instrumentos pendentes foi solicitado.','link'=>$link,'key'=>'inadimplencia:'.$id.':'.$user]);
   if($own)$this->db->commit();return true;
  }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function run(int $limit=100):int
 {$q=$this->db->query("SELECT id FROM reservas WHERE modalidade='entrada_parcelamento' AND entrada_confirmada_em IS NOT NULL AND status_reserva NOT IN ('cancelada','expirada','estornada','finalizada') AND EXISTS(SELECT 1 FROM obrigacoes_reserva o WHERE o.reserva_id=reservas.id AND o.numero>0 AND o.estado='pendente' AND LEAST(o.cancelamento_em,(reservas.precificacao_detalhes->>'limite_ultima_parcela')::timestamptz)<=clock_timestamp()) ORDER BY id LIMIT ".max(1,min($limit,500)));$count=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if($this->cancelIfDue((int)$id))$count++;return $count;}
}
