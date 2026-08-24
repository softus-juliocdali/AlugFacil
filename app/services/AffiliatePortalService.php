<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

final class AffiliatePortalService
{
    public function __construct(private ?PDO$db=null){$this->db??=Database::getConnection();}

    public function dashboard(int$affiliateId,?DateTimeImmutable$now=null):array
    {
        $now??=new DateTimeImmutable('now');$start=$now->modify('first day of this month')->setTime(0,0);$end=$start->modify('+1 month');
        $s=$this->db->prepare("SELECT COUNT(DISTINCT p.id) AS proprietarios,COUNT(DISTINCT c.id) AS chacaras,COUNT(DISTINCT c.id) FILTER(WHERE m.id IS NOT NULL AND m.ativa) AS chacaras_mensalidade FROM proprietarios p LEFT JOIN chacaras c ON c.proprietario_id=p.id LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id WHERE p.afiliado_id=:id");$s->execute(['id'=>$affiliateId]);$counts=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $s=$this->db->prepare('SELECT COUNT(*) AS pagamentos_mes,COALESCE(SUM(valor_comissao_centavos),0) AS comissao_mes FROM comissoes_afiliados WHERE afiliado_id=:id AND confirmado_em>=:inicio AND confirmado_em<:fim');$s->execute(['id'=>$affiliateId,'inicio'=>$start->format(DATE_ATOM),'fim'=>$end->format(DATE_ATOM)]);$month=$s->fetch(PDO::FETCH_ASSOC)?:[];
        return array_merge(['proprietarios'=>(int)($counts['proprietarios']??0),'chacaras'=>(int)($counts['chacaras']??0),'chacaras_mensalidade'=>(int)($counts['chacaras_mensalidade']??0),'pagamentos_mes'=>(int)($month['pagamentos_mes']??0),'comissao_mes_centavos'=>(int)($month['comissao_mes']??0)],(new AffiliateCommissionService($this->db))->summary($affiliateId,$now));
    }

    public function referredOwners(int$affiliateId,array$filters=[],int$page=1,int$perPage=20):array
    {
        $where=['p.afiliado_id=:afiliado'];$params=['afiliado'=>$affiliateId];
        foreach(['nome','email']as$field){$value=trim((string)($filters[$field]??''));if($value!==''){$where[]="p.$field ILIKE :$field";$params[$field]='%'.$value.'%';}}
        if($this->validDate($filters['de']??null)){$where[]='p.afiliado_atribuido_em::date>=:de';$params['de']=$filters['de'];}if($this->validDate($filters['ate']??null)){$where[]='p.afiliado_atribuido_em::date<=:ate';$params['ate']=$filters['ate'];}
        $clause=implode(' AND ',$where);$count=$this->db->prepare("SELECT COUNT(*) FROM proprietarios p WHERE $clause");$count->execute($params);$total=(int)$count->fetchColumn();$page=max(1,$page);$offset=($page-1)*$perPage;
        $sql="WITH propriedades AS(SELECT c.proprietario_id,COUNT(*) AS chacaras,COUNT(*) FILTER(WHERE m.id IS NOT NULL AND m.ativa) AS com_mensalidade FROM chacaras c LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id GROUP BY c.proprietario_id),financeiro AS(SELECT c.proprietario_id,SUM((CASE WHEN c.status IN ('ESTORNADA','CANCELADA') THEN 0 ELSE c.valor_comissao_centavos END)+COALESCE(a.valor_centavos,0)) AS comissao FROM comissoes_afiliados c LEFT JOIN ajustes_afiliados a ON a.comissao_afiliado_id=c.id GROUP BY c.proprietario_id) SELECT p.id,p.nome,p.email,p.telefone,p.status,p.data_cadastro,p.afiliado_origem,p.afiliado_atribuido_em,COALESCE(pr.chacaras,0) AS chacaras,COALESCE(pr.com_mensalidade,0) AS com_mensalidade,COALESCE(f.comissao,0) AS comissao_centavos FROM proprietarios p LEFT JOIN propriedades pr ON pr.proprietario_id=p.id LEFT JOIN financeiro f ON f.proprietario_id=p.id WHERE $clause ORDER BY p.afiliado_atribuido_em DESC NULLS LAST,p.id DESC LIMIT :limite OFFSET :offset";
        $s=$this->db->prepare($sql);foreach($params as$k=>$v)$s->bindValue(':'.$k,$v);$s->bindValue(':limite',$perPage,PDO::PARAM_INT);$s->bindValue(':offset',$offset,PDO::PARAM_INT);$s->execute();return['items'=>$s->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage))];
    }

    public function referredOwner(int$affiliateId,int$ownerId):?array
    {
        $s=$this->db->prepare("WITH financeiro AS(SELECT c.proprietario_id,COUNT(*) AS pagamentos,SUM((CASE WHEN c.status IN ('ESTORNADA','CANCELADA') THEN 0 ELSE c.valor_comissao_centavos END)+COALESCE(a.valor_centavos,0)) AS comissao FROM comissoes_afiliados c LEFT JOIN ajustes_afiliados a ON a.comissao_afiliado_id=c.id GROUP BY c.proprietario_id) SELECT p.id,p.nome,p.email,p.telefone,p.status,p.data_cadastro,p.afiliado_origem,p.afiliado_atribuido_em,COALESCE(f.pagamentos,0) AS pagamentos_comissao,COALESCE(f.comissao,0) AS comissao_centavos,(SELECT COUNT(*) FROM chacaras ch WHERE ch.proprietario_id=p.id) AS chacaras FROM proprietarios p LEFT JOIN financeiro f ON f.proprietario_id=p.id WHERE p.id=:owner AND p.afiliado_id=:affiliate LIMIT 1");$s->execute(['owner'=>$ownerId,'affiliate'=>$affiliateId]);$owner=$s->fetch(PDO::FETCH_ASSOC);if(!$owner)return null;
        $s=$this->db->prepare("WITH financeiro AS(SELECT c.chacara_id,COUNT(*) AS pagamentos,SUM((CASE WHEN c.status IN ('ESTORNADA','CANCELADA') THEN 0 ELSE c.valor_comissao_centavos END)+COALESCE(a.valor_centavos,0)) AS comissao FROM comissoes_afiliados c LEFT JOIN ajustes_afiliados a ON a.comissao_afiliado_id=c.id WHERE c.afiliado_id=:affiliate GROUP BY c.chacara_id) SELECT ch.id,ch.nome,ch.cidade,ch.estado,ch.status_aprovacao,ch.status_operacional,CASE WHEN m.id IS NULL THEN 'AGUARDANDO_CONFIGURACAO' ELSE m.status END AS mensalidade_status,COALESCE(f.pagamentos,0) AS pagamentos_comissao,COALESCE(f.comissao,0) AS comissao_centavos FROM chacaras ch INNER JOIN proprietarios p ON p.id=ch.proprietario_id LEFT JOIN mensalidades_anuncios m ON m.chacara_id=ch.id LEFT JOIN financeiro f ON f.chacara_id=ch.id WHERE ch.proprietario_id=:owner AND p.afiliado_id=:affiliate ORDER BY ch.nome,ch.id");$s->execute(['owner'=>$ownerId,'affiliate'=>$affiliateId]);$owner['chacaras_lista']=$s->fetchAll(PDO::FETCH_ASSOC);return$owner;
    }

    public function commissions(int$affiliateId,array$filters=[],int$page=1,int$perPage=30,?DateTimeImmutable$now=null):array
    {
        $now??=new DateTimeImmutable('now');$where=['x.afiliado_id=:affiliate'];$params=['affiliate'=>$affiliateId,'agora'=>$now->format(DATE_ATOM)];
        if($this->validDate($filters['de']??null)){$where[]='x.confirmado_em::date>=:de';$params['de']=$filters['de'];}if($this->validDate($filters['ate']??null)){$where[]='x.confirmado_em::date<=:ate';$params['ate']=$filters['ate'];}
        $status=strtoupper(trim((string)($filters['status']??'')));if(in_array($status,['EM_ABERTO','DISPONIVEL','PAGA','ESTORNADA','CANCELADA'],true)){$where[]='x.status_exibicao=:status';$params['status']=$status;}
        $owner=(int)($filters['proprietario']??0);if($owner>0){$where[]='x.proprietario_id=:owner';$params['owner']=$owner;}$property=trim((string)($filters['chacara']??''));if($property!==''){$where[]='x.chacara_nome ILIKE :chacara';$params['chacara']='%'.$property.'%';}
        $cte="WITH dados AS(SELECT c.id,c.afiliado_id,c.proprietario_id,c.chacara_id,c.confirmado_em,c.disponivel_em,c.percentual_bps,c.valor_comissao_centavos,p.nome AS proprietario_nome,ch.nome AS chacara_nome,CASE WHEN c.estornada_em IS NOT NULL AND c.status<>'PAGA' THEN 'ESTORNADA' WHEN c.status IN ('PAGA','ESTORNADA','CANCELADA') THEN c.status WHEN c.disponivel_em<=CAST(:agora AS TIMESTAMPTZ) THEN 'DISPONIVEL' ELSE 'EM_ABERTO' END AS status_exibicao FROM comissoes_afiliados c INNER JOIN proprietarios p ON p.id=c.proprietario_id INNER JOIN chacaras ch ON ch.id=c.chacara_id) SELECT * FROM dados x WHERE ".implode(' AND ',$where);
        $count=$this->db->prepare("SELECT COUNT(*) FROM ($cte) q");$count->execute($params);$total=(int)$count->fetchColumn();$page=max(1,$page);$sql=$cte.' ORDER BY x.confirmado_em DESC,x.id DESC LIMIT :limite OFFSET :offset';$s=$this->db->prepare($sql);foreach($params as$k=>$v)$s->bindValue(':'.$k,$v);$s->bindValue(':limite',$perPage,PDO::PARAM_INT);$s->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);$s->execute();return['items'=>$s->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage))];
    }

    public function ownerOptions(int$affiliateId):array{$s=$this->db->prepare('SELECT id,nome FROM proprietarios WHERE afiliado_id=:id ORDER BY nome,id');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function receivedPayments(int$affiliateId):array{$s=$this->db->prepare('SELECT data_pagamento,valor_centavos,referencia,registrado_em FROM pagamentos_afiliados WHERE afiliado_id=:id ORDER BY data_pagamento DESC,id DESC LIMIT 100');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function adjustments(int$affiliateId):array{$s=$this->db->prepare('SELECT a.criado_em,a.valor_centavos,ch.nome AS chacara_nome FROM ajustes_afiliados a INNER JOIN comissoes_afiliados c ON c.id=a.comissao_afiliado_id INNER JOIN chacaras ch ON ch.id=c.chacara_id WHERE a.afiliado_id=:id ORDER BY a.criado_em DESC,a.id DESC LIMIT 100');$s->execute(['id'=>$affiliateId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function validDate(mixed$value):bool{return is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)===1&&DateTimeImmutable::createFromFormat('!Y-m-d',$value)?->format('Y-m-d')===$value;}
}
