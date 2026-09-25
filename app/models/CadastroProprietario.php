<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Model;
use App\Services\AsaasSubcontaService;
use App\Services\CadastroBackfillService;
use DomainException;
use Throwable;

final class CadastroProprietario extends Model
{
    public function buscar(int $pid): ?array
    {
        $s=$this->db->prepare('SELECT * FROM proprietario_cadastro WHERE proprietario_id=:p');
        $s->execute(['p'=>$pid]);return $s->fetch()?:null;
    }

    public function conflitos(int $pid): array
    {
        $s=$this->db->prepare('SELECT id,campo,detectado_em FROM proprietario_cadastro_conflitos WHERE proprietario_id=:p AND resolvido_em IS NULL ORDER BY campo');
        $s->execute(['p'=>$pid]);return $s->fetchAll();
    }

    public function salvarUsuario(int $uid,array $input): void
    {
        try {$d=AsaasSubcontaService::validarDados($input);} catch(\RuntimeException $e) {throw new DomainException($e->getMessage());}
        $password=(string)($input['senha']??'');
        if($password!=='' && mb_strlen($password)<6) throw new DomainException('A nova senha deve ter pelo menos 6 caracteres.');
        foreach(['nome_razao_social'=>150,'nome_fantasia'=>150,'email_financeiro'=>160,'endereco'=>180,'numero'=>20,'complemento'=>100,'bairro'=>100,'cidade'=>100] as $field=>$max) {
            if(mb_strlen($d[$field])>$max) throw new DomainException('Campo '.$field.' excede '.$max.' caracteres.');
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT p.id FROM proprietarios p JOIN usuarios u ON u.id=p.usuario_id WHERE p.usuario_id=:u AND u.status='ativo' AND u.tipo_usuario='proprietario' FOR UPDATE OF p,u");
            $q->execute(['u'=>$uid]);$pid=(int)$q->fetchColumn();
            if(!$pid) throw new DomainException('Cadastro de proprietario indisponivel.');
            $old=$this->buscar($pid);
            if((int)($input['versao_cadastro']??-1)!==(int)($old['versao']??0)) throw new DomainException('O cadastro foi atualizado em outra janela. Recarregue antes de salvar.');
            $conflicts=$this->conflitos($pid);
            if($conflicts && (($input['confirmar_correcao']??'')!=='1' || mb_strlen(trim((string)($input['motivo_correcao']??'')))<10)) throw new DomainException('Revise os campos divergentes e informe o motivo da correcao cadastral.');
            $s=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM asaas_subcontas WHERE proprietario_id=:p AND (asaas_account_id IS NOT NULL OR status_local IN ('criando','conciliacao_manual')))");
            $s->execute(['p'=>$pid]);
            if($s->fetchColumn() && (!$old || !hash_equals((string)$old['cpf_cnpj'],$d['cpf_cnpj']))) throw new DomainException('Documento vinculado ou em conciliacao no Asaas: revisao administrativa necessaria.');
            $s=$this->db->prepare('SELECT EXISTS(SELECT 1 FROM proprietario_cadastro WHERE cpf_cnpj=:d AND proprietario_id<>:p)');
            $s->execute(['d'=>$d['cpf_cnpj'],'p'=>$pid]);
            if($s->fetchColumn()) throw new DomainException('CPF/CNPJ ja vinculado a outro proprietario.');
            $s=$this->db->prepare('SELECT EXISTS(SELECT 1 FROM usuarios WHERE LOWER(email)=LOWER(:e) AND id<>:u)');$s->execute(['e'=>$d['email_financeiro'],'u'=>$uid]);
            if($s->fetchColumn()) throw new DomainException('E-mail ja utilizado por outra conta.');
            $params=['proprietario_id'=>$pid];
            foreach(CadastroBackfillService::FIELDS as $field) $params[$field]=$d[$field]===''?null:$d[$field];
            $fields=array_keys($params);
            $updates=implode(',',array_map(fn($x)=>$x.'=EXCLUDED.'.$x,CadastroBackfillService::FIELDS));
            $this->db->prepare('INSERT INTO proprietario_cadastro('.implode(',',$fields).",dados_completos,situacao,validado_em) VALUES(".implode(',',array_map(fn($x)=>':'.$x,$fields)).",TRUE,'validado',CURRENT_TIMESTAMP) ON CONFLICT(proprietario_id) DO UPDATE SET ".$updates.",dados_completos=TRUE,situacao='validado',validado_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP,versao=proprietario_cadastro.versao+1")->execute($params);
            // Explicit compatibility projections. The legacy CPF and financial row stay preserved.
            $profile=['nome'=>$d['nome_razao_social'],'email'=>$d['email_financeiro'],'telefone'=>$d['telefone']?:$d['celular'],'u'=>$uid];
            $sql='UPDATE usuarios SET nome=:nome,email=:email,telefone=:telefone';
            if($password!=='') {$sql.=',senha_hash=:senha_hash';$profile['senha_hash']=password_hash($password,PASSWORD_DEFAULT);}
            $this->db->prepare($sql.' WHERE id=:u')->execute($profile);
            unset($profile['senha_hash']);
            $this->db->prepare('UPDATE proprietarios SET nome=:nome,email=:email,telefone=:telefone WHERE usuario_id=:u')->execute($profile);
            $changed=[];foreach($d as $key=>$value) if(($old[$key]??null)!=$value) $changed[]=$key;
            $this->db->prepare("INSERT INTO auditoria_dados_financeiros(proprietario_id,usuario_id,origem,campos_alterados) VALUES(:p,:u,'proprietario',CAST(:c AS jsonb))")->execute(['p'=>$pid,'u'=>$uid,'c'=>json_encode($changed,JSON_THROW_ON_ERROR)]);
            if($conflicts) $this->db->prepare('UPDATE proprietario_cadastro_conflitos SET resolvido_em=CURRENT_TIMESTAMP,resolvido_por=:u,justificativa=:m WHERE proprietario_id=:p AND resolvido_em IS NULL')->execute(['u'=>$uid,'p'=>$pid,'m'=>mb_substr(trim($input['motivo_correcao']),0,500)]);
            $this->db->commit();
        } catch(Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
