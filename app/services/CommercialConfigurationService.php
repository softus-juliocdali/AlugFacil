<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class CommercialConfigurationService
{
    public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
    public function global():array{return $this->db->query('SELECT * FROM configuracoes_mensalidades_anuncios WHERE id=1')->fetch()?:throw new RuntimeException('Configuracao global ausente.');}
    public function property(int $id):?array{$q=$this->db->prepare('SELECT * FROM configuracoes_comerciais_imoveis WHERE chacara_id=:id');$q->execute(['id'=>$id]);return $q->fetch()?:null;}
    public function ownerConditions(int $owner):array
    {$q=$this->db->prepare('SELECT c.id,c.nome,cc.sem_mensalidade,cc.comissao_bps,cc.versao,cc.aceita_parcelamento,cc.entrada_bps,g.ativa AS mensalidade_global_ativa,g.valor_padrao_centavos FROM chacaras c LEFT JOIN configuracoes_comerciais_imoveis cc ON cc.chacara_id=c.id CROSS JOIN configuracoes_mensalidades_anuncios g WHERE c.proprietario_id=:p AND g.id=1 ORDER BY c.id');$q->execute(['p'=>$owner]);return $q->fetchAll();}
    public function currentOwnerObligations(int $owner):array
    {
        $g=$this->global();
        if($g['ativa']){
            $q=$this->db->prepare("SELECT c.id,(SELECT MIN(o.vencimento) FROM obrigacoes_mensalidades o WHERE o.chacara_id=c.id) AS ancora FROM chacaras c JOIN configuracoes_comerciais_imoveis cc ON cc.chacara_id=c.id WHERE c.proprietario_id=:p AND c.status_aprovacao='aprovada' AND NOT cc.sem_mensalidade AND cc.comissao_bps IS NOT NULL");$q->execute(['p'=>$owner]);$properties=$q->fetchAll();
            $today=new \DateTimeImmutable('today');
            foreach($properties as $p){
                $due=$today;
                if($p['ancora']){$anchor=new \DateTimeImmutable($p['ancora']);$month=$today->modify('first day of this month');$day=min((int)$anchor->format('d'),(int)$month->format('t'));$due=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day);if($due>$today){$month=$month->modify('-1 month');$due=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$anchor->format('d'),(int)$month->format('t')));}}
                $this->monthlyObligation((int)$p['id'],$due->format('Y-m-d'));
            }
        }
        $q=$this->db->prepare('SELECT o.*,c.nome AS chacara_nome FROM obrigacoes_mensalidades o JOIN chacaras c ON c.id=o.chacara_id WHERE o.proprietario_id=:p ORDER BY o.vencimento DESC,o.id DESC');$q->execute(['p'=>$owner]);return $q->fetchAll();
    }
    public function configureGlobal(bool $active,int $value,int $admin,string $reason):void
    {
        if($value<100)throw new RuntimeException('Valor global minimo: R$ 1,00.');
        $this->change('global',1,$admin,$reason,function()use($active,$value,$admin):array{
            $old=$this->db->query('SELECT * FROM configuracoes_mensalidades_anuncios WHERE id=1 FOR UPDATE')->fetch();
            $q=$this->db->prepare('UPDATE configuracoes_mensalidades_anuncios SET ativa=:a,valor_padrao_centavos=:v,versao=versao+1,atualizado_por=:u,atualizada_em=clock_timestamp() WHERE id=1 RETURNING *');$q->execute(['a'=>$active,'v'=>$value,'u'=>$admin]);return [$old,$q->fetch()];
        });
    }
    public function configureProperty(int $id,bool $exempt,int $bps,int $admin,string $reason):void
    {
        self::percent($bps);
        $this->change('imovel',$id,$admin,$reason,function()use($id,$exempt,$bps,$admin):array{
            $q=$this->db->prepare('SELECT id FROM chacaras WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);if(!$q->fetchColumn())throw new RuntimeException('Imovel nao encontrado.');$old=$this->property($id);
            $q=$this->db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade,comissao_bps,atualizado_por) VALUES(:id,:e,:b,:u) ON CONFLICT(chacara_id) DO UPDATE SET sem_mensalidade=EXCLUDED.sem_mensalidade,comissao_bps=EXCLUDED.comissao_bps,versao=configuracoes_comerciais_imoveis.versao+1,atualizado_por=EXCLUDED.atualizado_por,atualizado_em=clock_timestamp() RETURNING *');$q->execute(['id'=>$id,'e'=>$exempt,'b'=>$bps,'u'=>$admin]);return [$old,$q->fetch()];
        });
    }
    public function configureAffiliate(int $id,int $bps,int $admin,string $reason):void
    {
        self::percent($bps);
        $this->change('afiliado',$id,$admin,$reason,function()use($id,$bps):array{
            $q=$this->db->prepare('SELECT id,percentual_comissao_bps,versao_comercial FROM afiliados WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);$old=$q->fetch();if(!$old)throw new RuntimeException('Afiliado nao encontrado.');
            $q=$this->db->prepare('UPDATE afiliados SET percentual_comissao_bps=:b,versao_comercial=versao_comercial+1 WHERE id=:id RETURNING id,percentual_comissao_bps,versao_comercial');$q->execute(['b'=>$bps,'id'=>$id]);return [$old,$q->fetch()];
        });
    }
    /** Snapshot before the external charge; all monetary values are integer cents. */
    public function monthlyObligation(int $id,string $due):?array
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$due);if(!$date||$date->format('Y-m-d')!==$due)throw new RuntimeException('Vencimento invalido.');
        if($this->db->inTransaction())throw new RuntimeException('Obrigacao exige transacao propria.');
        $this->db->beginTransaction();try {
            $g=$this->db->query('SELECT * FROM configuracoes_mensalidades_anuncios WHERE id=1 FOR SHARE')->fetch();
            $q=$this->db->prepare('SELECT c.proprietario_id,c.status_aprovacao,p.afiliado_id FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id WHERE c.id=:id FOR UPDATE OF c');$q->execute(['id'=>$id]);$property=$q->fetch();if(!$property)throw new RuntimeException('Imovel nao encontrado.');
            $q=$this->db->prepare('SELECT * FROM obrigacoes_mensalidades WHERE chacara_id=:id AND vencimento=:d');$q->execute(['id'=>$id,'d'=>$due]);$existing=$q->fetch();if($existing){$this->db->commit();return $existing;}
            $commercial=$this->property($id);
            if(!$g['ativa']||($commercial['sem_mensalidade']??false)||$property['status_aprovacao']!=='aprovada'){$this->db->commit();return null;}
            if(!$commercial||$commercial['comissao_bps']===null)throw new RuntimeException('Configure a comissao individual do imovel.');
            $affiliate=null;if($property['afiliado_id']){$q=$this->db->prepare('SELECT id,percentual_comissao_bps,versao_comercial FROM afiliados WHERE id=:id FOR SHARE');$q->execute(['id'=>$property['afiliado_id']]);$affiliate=$q->fetch()?:null;}
            $value=(int)$g['valor_padrao_centavos'];$bps=(int)($affiliate['percentual_comissao_bps']??0);
            $q=$this->db->prepare('INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,vencimento,valor_centavos,versao_global,versao_imovel,afiliado_id,percentual_afiliado_bps,comissao_afiliado_centavos,versao_afiliado) VALUES(:c,:p,:d,:v,:g,:i,:a,:b,:fee,:av) RETURNING *');$q->execute(['c'=>$id,'p'=>$property['proprietario_id'],'d'=>$due,'v'=>$value,'g'=>$g['versao'],'i'=>$commercial['versao'],'a'=>$affiliate['id']??null,'b'=>$bps,'fee'=>intdiv($value*$bps+5000,10000),'av'=>$affiliate['versao_comercial']??null]);$out=$q->fetch();$this->db->commit();return $out;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function percent(int $bps):void{if($bps<0||$bps>10000)throw new RuntimeException('Percentual deve estar entre 0 e 100%.');}
    private function change(string $type,int $id,int $admin,string $reason,callable $write):void
    {
        if(trim($reason)==='')throw new RuntimeException('Motivo obrigatorio.');
        $this->db->beginTransaction();try {
            $q=$this->db->prepare("SELECT id FROM usuarios WHERE id=:id AND tipo_usuario='admin' AND status='ativo' FOR SHARE");$q->execute(['id'=>$admin]);if(!$q->fetchColumn())throw new RuntimeException('Somente administrador ativo altera condicoes comerciais.');
            [$old,$new]=$write();$this->db->prepare('INSERT INTO historico_condicoes_comerciais(tipo,entidade_id,administrador_id,motivo,anterior,nova) VALUES(:t,:id,:a,:m,CAST(:old AS jsonb),CAST(:new AS jsonb))')->execute(['t'=>$type,'id'=>$id,'a'=>$admin,'m'=>mb_substr(trim($reason),0,1000),'old'=>$old?json_encode($old,JSON_THROW_ON_ERROR):null,'new'=>json_encode($new,JSON_THROW_ON_ERROR)]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
