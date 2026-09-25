<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use App\Models\Reserva;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class CheckoutQuoteService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public function create(int $user,int $property,string $start,string $end,string $mode,string $method):array
 {
  if(FinancialReleasePolicy::enabled()||getenv('APP_ENV')==='production'){
   FinancialReleasePolicy::assertTestReservation($user,$property);
   if(!in_array($method,['PIX','BOLETO'],true))throw new RuntimeException('Teste Sandbox disponivel por PIX ou boleto; cartao ainda nao homologado.');
  }
  if(!Reserva::periodoValido($start,$end))throw new RuntimeException('Periodo invalido.');
  if(!in_array($method,$mode==='integral'?['PIX','CREDIT_CARD']:['PIX','CREDIT_CARD','BOLETO'],true))throw new RuntimeException('Meio de pagamento indisponivel para esta modalidade.');
  $this->db->beginTransaction();try{
   $this->lock($property);
   $cfg=$this->db->query('SELECT * FROM configuracoes_financeiras WHERE vigencia_fim IS NULL AND precificacao_ativa FOR SHARE')->fetch();
   if(!$cfg)throw new RuntimeException('Precificacao sem configuracao ativa.');
   $this->db->query('SELECT id FROM configuracoes_parcelamento WHERE id=1 FOR SHARE')->fetch();
   $this->operational($property);
   $q=$this->db->prepare('SELECT * FROM configuracoes_comerciais_imoveis WHERE chacara_id=:id FOR SHARE');$q->execute(['id'=>$property]);$commercial=$q->fetch();
   if(!$commercial||$commercial['comissao_bps']===null)throw new RuntimeException('Comissao do imovel ainda nao configurada.');
   (new ReservationAuthorization($this->db))->assertCanBook($user,$property);
   $model=new Reserva();$c=$model->buscarChacaraParaReserva($property);if(!$c)throw new RuntimeException('Imovel indisponivel para novas reservas.');
   $available=$this->db->prepare('SELECT agenda_ocupada(:c,CAST(:i AS date),CAST(:f AS date))');$available->execute(['c'=>$property,'i'=>$start,'f'=>$end]);if($available->fetchColumn())throw new RuntimeException('Periodo em checkout ou reservado.');
   $settings=(new PropertyPaymentConfigurationService($this->db))->effective($property);
   if($mode==='entrada_parcelamento'&&!$settings['aceita_parcelamento'])throw new RuntimeException('Parcelamento indisponivel neste imovel.');
   $now=new DateTimeImmutable($this->db->query('SELECT clock_timestamp()')->fetchColumn());
   $zone=new DateTimeZone('America/Sao_Paulo');$checkin=new DateTimeImmutable($start.' '.$c['checkin_hora_inicial'],$zone);
   if($checkin<=$now)throw new RuntimeException('O inicio do check-in deve estar no futuro.');
   $deadline=$checkin->setTimestamp($checkin->getTimestamp()-86400);
   $n=Reserva::calcularDiarias($start,$end);$daily=PrecificacaoReservaService::decimalParaCentavos((string)$c['valor_diaria']);
   $s=ReservationPricingEngine::calculate($daily*$n,(int)$cfg['taxa_operacao_pix_reserva_centavos'],(int)$commercial['comissao_bps'],(int)($settings['entrada_bps']??10000),$mode,$now,$checkin);
   $s+=['schema_financeiro'=>2,'proprietario_id'=>(int)$c['proprietario_id'],'quantidade_diarias'=>$n,'diaria_hospedagem_centavos'=>$daily,
    'valor_diaria_liquido_proprietario_centavos'=>intdiv($s['direito_proprietario_centavos'],$n),'valor_reserva_centavos'=>$s['valor_hospedagem_centavos'],
    'valor_liquido_proprietario_centavos'=>$s['direito_proprietario_centavos'],'taxa_operacao_pix_centavos'=>$s['taxa_operacional_centavos'],
    'taxa_plataforma_centavos'=>$s['comissao_imovel_centavos'],'valor_total_cliente_centavos'=>$s['total_centavos'],
    'plataforma_percentual_bps'=>$s['comissao_imovel_bps'],'plataforma_fixa_centavos'=>0,'taxa_gateway_estimada_centavos'=>0,'gateway_percentual_bps'=>0,'gateway_fixa_centavos'=>0,'margem_seguranca_bps'=>0,
    'forma_pagamento'=>$method,'quantidade_parcelas'=>$s['quantidade_pagamentos'],'configuracao_financeira_id'=>(int)$cfg['id'],'versao_precificacao'=>(int)$cfg['versao'],
    'versao_imovel'=>(int)$commercial['versao'],'versao_limites'=>$settings['versao_limites'],'origem_precificacao'=>'financeiro_v2',
    'checkin_hora_inicial_snapshot'=>$c['checkin_hora_inicial'],'checkin_hora_final_snapshot'=>$c['checkin_hora_final'],'checkout_hora_inicial_snapshot'=>$c['checkout_hora_inicial'],'checkout_hora_final_snapshot'=>$c['checkout_hora_final'],
    'checkin_inicio_em'=>$checkin->format(DATE_ATOM),'cancelamento_permitido_ate'=>$deadline->format(DATE_ATOM),'repasse_liberavel_em'=>$deadline->format(DATE_ATOM)];
   $hex=bin2hex(random_bytes(16));$id=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20);
   $q=$this->db->prepare("INSERT INTO cotacoes_reserva(id,usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,forma_pagamento,quantidade_parcelas,detalhes,criado_em,expira_em,versao_snapshot,modalidade) VALUES(:id,:u,:c,:cfg,:v,:i,:f,:method,:n,CAST(:s AS jsonb),CAST(:now AS timestamptz),CAST(:expiry AS timestamptz),2,:mode) RETURNING *");
   $q->execute(['id'=>$id,'u'=>$user,'c'=>$property,'cfg'=>$cfg['id'],'v'=>$cfg['versao'],'i'=>$start,'f'=>$end,'method'=>$method,'n'=>$s['quantidade_pagamentos'],'s'=>json_encode($s,JSON_THROW_ON_ERROR),'now'=>$now->format('Y-m-d H:i:s.uP'),'expiry'=>$now->modify('+15 minutes')->format('Y-m-d H:i:s.uP'),'mode'=>$mode]);
   $result=$q->fetch();$result['detalhes']=$s;$this->db->commit();return $result;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function valid(string $id,int $user,int $property,bool $lock=false):array
 {
  if(!preg_match('/^[a-f0-9-]{36}$/i',$id))throw new RuntimeException('Cotacao invalida.');
  $q=$this->db->prepare('SELECT *,expira_em>clock_timestamp() AS valida FROM cotacoes_reserva WHERE id=:id AND usuario_id=:u AND chacara_id=:c'.($lock?' FOR UPDATE':''));$q->execute(['id'=>$id,'u'=>$user,'c'=>$property]);$r=$q->fetch();
  if(!$r||!$r['valida']||$r['consumida_em']!==null||$r['cancelada_em']!==null||$r['versao_snapshot']!==2)throw new RuntimeException('Cotacao expirada ou invalida. Solicite uma nova cotacao.');
  $r['detalhes']=json_decode($r['detalhes'],true,512,JSON_THROW_ON_ERROR);return $r;
 }
 public function consume(string $id,int $user,int $property,string $start,string $end,bool $accepted):array
 {
  $this->db->beginTransaction();try{
   $this->lock($property);$this->operational($property);(new ReservationAuthorization($this->db))->assertCanBook($user,$property);
   if(!preg_match('/^[a-f0-9-]{36}$/i',$id))throw new RuntimeException('Cotacao invalida.');
   $q=$this->db->prepare('SELECT id,data_inicio,data_fim FROM reservas WHERE cotacao_id=:q AND usuario_id=:u AND chacara_id=:c');$q->execute(['q'=>$id,'u'=>$user,'c'=>$property]);$existing=$q->fetch();
   if($existing){if($existing['data_inicio']!==$start||$existing['data_fim']!==$end)throw new RuntimeException('Periodo diverge da cotacao.');$this->db->commit();return ['id'=>(int)$existing['id']];}
   $q=$this->valid($id,$user,$property,true);if($q['data_inicio']!==$start||$q['data_fim']!==$end)throw new RuntimeException('Periodo diverge da cotacao.');$s=$q['detalhes'];
   $now=new DateTimeImmutable($this->db->query('SELECT clock_timestamp()')->fetchColumn());$late=$now>=new DateTimeImmutable($s['cancelamento_permitido_ate']);
   if($late&&!$accepted)throw new RuntimeException('Confirme a condicao de cancelamento para continuar.');
   $r=(new Reserva())->criar($s+['cotacao_id'=>$id,'usuario_id'=>$user,'chacara_id'=>$property,'data_inicio'=>$start,'data_fim'=>$end,'valor_diaria'=>PrecificacaoReservaService::centavosParaDecimal($s['diaria_hospedagem_centavos']),'valor_total'=>PrecificacaoReservaService::centavosParaDecimal($s['valor_hospedagem_centavos']),'precificacao_detalhes'=>$s,'expira_em'=>$q['expira_em'],'aceite_reserva_menos_24h'=>$late]);
   $stmt=$this->db->prepare('UPDATE cotacoes_reserva SET consumida_em=clock_timestamp() WHERE id=:id AND consumida_em IS NULL AND expira_em>clock_timestamp()');$stmt->execute(['id'=>$id]);if($stmt->rowCount()!==1)throw new RuntimeException('Cotacao expirou durante a confirmacao.');
   $this->db->commit();return $r;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function lock(int $property):void{$this->db->prepare('SELECT pg_advisory_xact_lock(:c)')->execute(['c'=>$property]);}
 private function operational(int $property):void
 {$q=$this->db->prepare("SELECT c.id FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id JOIN usuarios u ON u.id=p.usuario_id WHERE c.id=:c AND c.status_aprovacao='aprovada' AND c.status_operacional='disponivel' AND u.status='ativo' FOR SHARE OF c,p,u");$q->execute(['c'=>$property]);if(!$q->fetchColumn())throw new RuntimeException('Imovel ou proprietario indisponivel.');}
}
