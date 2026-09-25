<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;use PDO;
final class FinanceiroProprietario extends Model
{
 public function buscar(int $pid):?array{return (new CadastroProprietario())->buscar($pid);}
 public function historico(int $pid):array{$s=$this->db->prepare('SELECT status_anterior,status_novo,origem,criado_em FROM historico_asaas_subcontas WHERE proprietario_id=:p ORDER BY criado_em DESC,id DESC LIMIT 50');$s->execute(['p'=>$pid]);return $s->fetchAll(PDO::FETCH_ASSOC);}
 public function provisionamento(int $pid):?array{$s=$this->db->prepare('SELECT estado,atualizado_em,erro_codigo FROM onboarding_fila WHERE proprietario_id=:p');$s->execute(['p'=>$pid]);return $s->fetch()?:null;}
 public function salvar(int $pid,array $d,int $uid):void{throw new \DomainException('Atualize os dados na area Dados Cadastrais.');}
 public function aceiteVigente(int $pid):bool{$s=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM aceites_financeiros_proprietarios WHERE proprietario_id=:p AND tipo_aceite='ONBOARDING_ASAAS_NON_BAAS' AND revogado_em IS NULL)");$s->execute(['p'=>$pid]);return(bool)$s->fetchColumn();}
 public function aceitar(int $pid,string $hash,string $ip,string $ua):void{$this->db->prepare("INSERT INTO aceites_financeiros_proprietarios(proprietario_id,tipo_aceite,versao_documento,texto_hash,ip,user_agent_resumido) VALUES(:p,'ONBOARDING_ASAAS_NON_BAAS','operacional-v1-revisao-juridica-pendente',:h,:ip,:ua) ON CONFLICT DO NOTHING")->execute(['p'=>$pid,'h'=>$hash,'ip'=>mb_substr($ip,0,45),'ua'=>mb_substr($ua,0,255)]);}
 public function subconta(int $pid,string $amb='sandbox'):?array{$s=$this->db->prepare('SELECT * FROM asaas_subcontas WHERE proprietario_id=:p AND ambiente=:a');$s->execute(['p'=>$pid,'a'=>$amb]);return$s->fetch()?:null;}
 public function listarAdmin(string $status=''):array{$expr="CASE WHEN s.id IS NOT NULL THEN s.status_local WHEN d.id IS NULL OR NOT d.dados_completos THEN 'dados_incompletos' WHEN a.id IS NULL THEN 'aguardando_aceite' ELSE 'pronto_para_criacao' END";$where=$status!==''?"WHERE ($expr)=:status":'';$s=$this->db->prepare("SELECT p.id,p.nome,p.email,$expr status_local,s.asaas_account_id,s.asaas_wallet_id,s.ultima_sincronizacao_em FROM proprietarios p LEFT JOIN proprietario_cadastro d ON d.proprietario_id=p.id LEFT JOIN aceites_financeiros_proprietarios a ON a.proprietario_id=p.id AND a.tipo_aceite='ONBOARDING_ASAAS_NON_BAAS' AND a.revogado_em IS NULL LEFT JOIN asaas_subcontas s ON s.proprietario_id=p.id AND s.ambiente='sandbox' $where ORDER BY p.id DESC");$s->execute($status!==''?['status'=>$status]:[]);return$s->fetchAll(PDO::FETCH_ASSOC);}
}
