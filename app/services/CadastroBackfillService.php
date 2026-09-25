<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class CadastroBackfillService
{
    public const FIELDS = ['tipo_pessoa','cpf_cnpj','nome_razao_social','nome_fantasia','data_nascimento',
        'tipo_empresa','renda_faturamento_mensal_centavos','telefone','celular','email_financeiro',
        'cep','endereco','numero','complemento','bairro','cidade','estado'];

    public static function normalizar(string $field, mixed $value): ?string
    {
        $v=trim((string)$value);
        if($v==='') return null;
        if(in_array($field,['cpf_cnpj','cep','telefone','celular'],true)) $v=preg_replace('/\D+/','',$v);
        if($field==='email_financeiro') $v=mb_strtolower($v);
        if(in_array($field,['estado','tipo_pessoa'],true)) $v=strtoupper($v);
        return $v===''?null:$v;
    }

    public function executar(PDO $db): array
    {
        $owners=$db->query('SELECT p.*,u.nome usuario_nome,u.email usuario_email,u.telefone usuario_telefone FROM proprietarios p JOIN usuarios u ON u.id=p.usuario_id ORDER BY p.id')->fetchAll(PDO::FETCH_ASSOC);
        $financial=[];
        foreach($db->query('SELECT * FROM proprietario_dados_financeiros')->fetchAll(PDO::FETCH_ASSOC) as $row) $financial[$row['proprietario_id']]=$row;
        $documentOwners=[];
        foreach($owners as $p) foreach([$p['cpf']??null,$financial[$p['id']]['cpf_cnpj']??null] as $doc) {
            $doc=self::normalizar('cpf_cnpj',$doc);
            if($doc!==null) $documentOwners[$doc][(int)$p['id']]=true;
        }
        $report=[];
        foreach($owners as $p) {
            $f=$financial[$p['id']]??[];$data=[];$conflicts=[];
            foreach(self::FIELDS as $field) {
                $candidates=['proprietario_dados_financeiros'=>$f[$field]??null];
                $legacy=match($field){'cpf_cnpj'=>'cpf','nome_razao_social'=>'nome','email_financeiro'=>'email','telefone'=>'telefone',default=>null};
                if($legacy!==null) $candidates['proprietarios']=$p[$legacy]??null;
                if(in_array($field,['nome_razao_social','email_financeiro','telefone'],true)) $candidates['usuarios']=$p['usuario_'.$legacy]??null;
                if($field==='tipo_pessoa' && !empty($p['cpf'])) $candidates['proprietarios']='PF';
                $values=[];
                foreach($candidates as $source=>$value) { $v=self::normalizar($field,$value); if($v!==null) $values[$source]=$v; }
                $distinct=array_values(array_unique(array_values($values)));
                $duplicate=$field==='cpf_cnpj' && count($distinct)===1 && count($documentOwners[$distinct[0]]??[])>1;
                if(count($distinct)>1 || $duplicate) {
                    $conflicts[$field]=$values;
                    if($duplicate) $conflicts[$field]['proprietarios_com_mesmo_documento']=array_keys($documentOwners[$distinct[0]]);
                    $data[$field]=null;
                } else $data[$field]=$distinct[0]??null;
            }
            if($data['tipo_pessoa']===null) $data['cpf_cnpj']=null;
            $complete=false;
            if(!$conflicts) try {
                AsaasSubcontaService::validarDados($data+['renda_faturamento_mensal'=>number_format((int)$data['renda_faturamento_mensal_centavos']/100,2,',','')]);
                $complete=true;
            } catch(\RuntimeException) {}
            $params=$data+['proprietario_id'=>$p['id'],'dados_completos'=>$complete,'situacao'=>$conflicts?'conflito':($complete?'validado':'incompleto')];
            $fields=array_keys($params);
            $db->prepare('INSERT INTO proprietario_cadastro('.implode(',',$fields).') VALUES('.implode(',',array_map(fn($x)=>':'.$x,$fields)).')')->execute($params);
            foreach($conflicts as $field=>$sources) {
                $db->prepare('INSERT INTO proprietario_cadastro_conflitos(proprietario_id,campo,fontes) VALUES(:p,:c,CAST(:f AS jsonb))')->execute(['p'=>$p['id'],'c'=>$field,'f'=>json_encode($sources,JSON_THROW_ON_ERROR)]);
                $report[]=['proprietario_id'=>(int)$p['id'],'campo'=>$field,'situacao'=>'pendente'];
            }
        }
        return $report;
    }
}
