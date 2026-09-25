<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;
final class PropertyPaymentConfigurationService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public function limits():array{return $this->db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();}
 public function effective(int $id):array
 {
  $p=(new CommercialConfigurationService($this->db))->property($id)??[];$g=$this->limits();$bps=$p['entrada_bps']??null;
  $allowed=!empty($p['aceita_parcelamento'])&&$g['entrada_minima_bps']!==null&&$bps!==null&&$bps>=$g['entrada_minima_bps']&&$bps<=$g['entrada_maxima_bps'];
  return ['aceita_parcelamento'=>$allowed,'solicitado'=>!empty($p['aceita_parcelamento']),'entrada_bps'=>$bps,'versao_imovel'=>(int)($p['versao']??0),'versao_limites'=>(int)$g['versao'],'minimo_bps'=>$g['entrada_minima_bps'],'maximo_bps'=>$g['entrada_maxima_bps']];
 }
 public function saveLimits(int $min,int $max,int $admin):void
 {
  if($min<=0||$max>=10000||$min>$max)throw new RuntimeException('A entrada deve ser maior que 0% e menor que 100%, com minimo nao superior ao maximo.');
  $this->db->beginTransaction();try{
   $q=$this->db->prepare("SELECT id FROM usuarios WHERE id=:id AND tipo_usuario='admin' AND status='ativo' FOR SHARE");$q->execute(['id'=>$admin]);if(!$q->fetchColumn())throw new RuntimeException('Somente administrador ativo define a faixa.');
   $old=$this->db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1 FOR UPDATE')->fetch();
   $q=$this->db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max,versao=versao+1,atualizado_por=:u,atualizado_em=clock_timestamp() WHERE id=1 RETURNING *');$q->execute(['min'=>$min,'max'=>$max,'u'=>$admin]);$this->history(null,$admin,$old,$q->fetch());$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function saveOwner(int $id,int $user,bool $enabled,?int $bps,int $version):void
 {
  // Property creation owns the outer transaction so both writes are atomic.
  $ownsTransaction = !$this->db->inTransaction();
  if ($ownsTransaction) $this->db->beginTransaction();
  try{
   $limits=$this->db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1 FOR SHARE')->fetch();
   $q=$this->db->prepare("SELECT c.id FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id JOIN usuarios u ON u.id=p.usuario_id WHERE c.id=:id AND u.id=:u AND u.status='ativo' FOR UPDATE OF c");$q->execute(['id'=>$id,'u'=>$user]);if(!$q->fetchColumn())throw new RuntimeException('Imovel indisponivel para esta identidade.');
   $old=(new CommercialConfigurationService($this->db))->property($id);if((int)($old['versao']??0)!==$version)throw new RuntimeException('Condicao alterada em outra aba. Recarregue antes de salvar.');
   if($enabled&&($limits['entrada_minima_bps']===null||$bps===null||$bps<$limits['entrada_minima_bps']||$bps>$limits['entrada_maxima_bps']))throw new RuntimeException('Escolha a entrada dentro da faixa definida pelo administrador.');
   $q=$this->db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,aceita_parcelamento,entrada_bps) VALUES(:id,:enabled,:bps) ON CONFLICT(chacara_id) DO UPDATE SET aceita_parcelamento=EXCLUDED.aceita_parcelamento,entrada_bps=EXCLUDED.entrada_bps,versao=configuracoes_comerciais_imoveis.versao+1,atualizado_em=clock_timestamp() RETURNING *');$q->execute(['id'=>$id,'enabled'=>$enabled,'bps'=>$enabled?$bps:null]);$this->history($id,$user,$old,$q->fetch());if ($ownsTransaction) $this->db->commit();
  }catch(Throwable $e){if($ownsTransaction && $this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function history(?int $id,int $user,?array $old,array $new):void{$this->db->prepare('INSERT INTO historico_condicoes_pagamento(chacara_id,usuario_id,anterior,nova) VALUES(:id,:u,CAST(:a AS jsonb),CAST(:n AS jsonb))')->execute(['id'=>$id,'u'=>$user,'a'=>$old?json_encode($old,JSON_THROW_ON_ERROR):null,'n'=>json_encode($new,JSON_THROW_ON_ERROR)]);}
}
