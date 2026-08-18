<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Model;
use PDO;

final class MensalidadeAnuncio extends Model
{
    public function listarAdministrativo(string $status = '', string $busca = ''): array
    {
        $where=[];$params=[];
        $status=strtoupper(trim($status));
        if(in_array($status,['EM_DIA','PENDENTE','ATRASADA','CANCELADA'],true)){
            $where[]='m.status = :status';$params['status']=$status;
        }elseif($status==='SEM_MENSALIDADE'){
            $where[]="(m.id IS NULL OR m.status = 'SEM_MENSALIDADE')";
        }
        $busca=trim($busca);
        if($busca!==''){
            $where[]='(LOWER(c.nome) LIKE LOWER(:busca) OR LOWER(p.nome) LIKE LOWER(:busca))';
            $params['busca']='%'.$busca.'%';
        }
        $sql=<<<'SQL'
            SELECT c.id AS chacara_id,c.nome AS chacara_nome,c.status_aprovacao,c.status_operacional,
                   p.id AS proprietario_id,p.nome AS proprietario_nome,
                   m.id AS mensalidade_id,m.valor_centavos,m.status,m.ativa,m.asaas_subscription_id,
                   m.proximo_vencimento,m.ultimo_pagamento_em,m.ultima_falha_sincronizacao,
                   CASE WHEN m.ativa THEN 1 ELSE 0 END AS ativa_flag,
                   (SELECT cm.vencimento FROM cobrancas_mensalidades cm WHERE cm.mensalidade_id=m.id ORDER BY cm.vencimento DESC NULLS LAST,cm.id DESC LIMIT 1) AS vencimento_atual,
                   (SELECT cm.pago_em FROM cobrancas_mensalidades cm WHERE cm.mensalidade_id=m.id AND cm.status='PAGA' ORDER BY cm.pago_em DESC NULLS LAST,cm.id DESC LIMIT 1) AS ultimo_pagamento
            FROM chacaras c
            INNER JOIN proprietarios p ON p.id=c.proprietario_id
            LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id
            SQL;
        if($where)$sql.=' WHERE '.implode(' AND ',$where);
        $sql.=' ORDER BY c.nome ASC,c.id ASC';
        $s=$this->db->prepare($sql);$s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarPorProprietario(int $proprietarioId): array
    {
        $s=$this->db->prepare(<<<'SQL'
            SELECT m.*,CASE WHEN m.ativa THEN 1 ELSE 0 END AS ativa_flag,c.nome AS chacara_nome,
              (SELECT cm.invoice_url FROM cobrancas_mensalidades cm WHERE cm.mensalidade_id=m.id AND cm.status IN ('PENDENTE','ATRASADA') ORDER BY cm.vencimento DESC NULLS LAST,cm.id DESC LIMIT 1) invoice_url,
              (SELECT cm.vencimento FROM cobrancas_mensalidades cm WHERE cm.mensalidade_id=m.id ORDER BY cm.vencimento DESC NULLS LAST,cm.id DESC LIMIT 1) ultimo_vencimento
            FROM mensalidades_anuncios m INNER JOIN chacaras c ON c.id=m.chacara_id
            WHERE m.proprietario_id=:proprietario ORDER BY c.nome
            SQL);
        $s->execute(['proprietario'=>$proprietarioId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function historicoPorProprietario(int $proprietarioId): array
    {
        $s=$this->db->prepare(<<<'SQL'
            SELECT cm.*,c.nome AS chacara_nome FROM cobrancas_mensalidades cm
            INNER JOIN mensalidades_anuncios m ON m.id=cm.mensalidade_id
            INNER JOIN chacaras c ON c.id=m.chacara_id
            WHERE m.proprietario_id=:proprietario ORDER BY cm.vencimento DESC NULLS LAST,cm.id DESC LIMIT 100
            SQL);
        $s->execute(['proprietario'=>$proprietarioId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarNotificacoes(int $usuarioId,int $limite=20): array
    {
        $s=$this->db->prepare('SELECT * FROM notificacoes WHERE usuario_id=:usuario ORDER BY criada_em DESC,id DESC LIMIT :limite');
        $s->bindValue(':usuario',$usuarioId,PDO::PARAM_INT);$s->bindValue(':limite',max(1,$limite),PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarAdministrativa(int $chacaraId): ?array
    {
        $s=$this->db->prepare(<<<'SQL'
            SELECT m.*,CASE WHEN m.ativa THEN 1 ELSE 0 END AS ativa_flag,c.nome AS chacara_nome,p.nome AS proprietario_nome
            FROM mensalidades_anuncios m INNER JOIN chacaras c ON c.id=m.chacara_id
            INNER JOIN proprietarios p ON p.id=m.proprietario_id WHERE m.chacara_id=:chacara LIMIT 1
            SQL);
        $s->execute(['chacara'=>$chacaraId]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function historicoAdministrativo(int $chacaraId): array
    {
        $s=$this->db->prepare(<<<'SQL'
            SELECT h.* FROM historico_mensalidades_anuncios h INNER JOIN mensalidades_anuncios m ON m.id=h.mensalidade_id
            WHERE m.chacara_id=:chacara ORDER BY h.criado_em DESC,h.id DESC LIMIT 100
            SQL);
        $s->execute(['chacara'=>$chacaraId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cobrancasAdministrativas(int $chacaraId): array
    {
        $s=$this->db->prepare(<<<'SQL'
            SELECT cm.* FROM cobrancas_mensalidades cm
            INNER JOIN mensalidades_anuncios m ON m.id=cm.mensalidade_id
            WHERE m.chacara_id=:chacara
            ORDER BY cm.vencimento DESC NULLS LAST,cm.id DESC
            LIMIT 100
            SQL);
        $s->execute(['chacara'=>$chacaraId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
