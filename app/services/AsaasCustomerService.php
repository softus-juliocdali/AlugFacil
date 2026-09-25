<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;
use RuntimeException;

final class AsaasCustomerService
{
    private string $scope;
    public function __construct(private AsaasPaymentClientInterface $client,private ?PDO $db=null,?string $scope=null)
    {$this->db??=Database::getConnection();$this->scope=$scope??(method_exists($client,'accountScope')?$client->accountScope():throw new RuntimeException('Escopo da conta necessario.'));}

    public function obter(int $uid):array
    {
        $q=$this->db->prepare('SELECT u.*,p.id proprietario_id,d.cpf_cnpj documento_proprietario,d.tipo_pessoa tipo_proprietario,d.nome_razao_social,d.dados_completos,d.email_financeiro,d.celular,d.cep,d.endereco,d.numero,d.bairro,ud.cpf_cnpj documento_usuario,ud.tipo_pessoa tipo_documento_usuario FROM usuarios u LEFT JOIN proprietarios p ON p.usuario_id=u.id LEFT JOIN proprietario_cadastro d ON d.proprietario_id=p.id LEFT JOIN usuario_documentos ud ON ud.usuario_id=u.id WHERE u.id=:u');$q->execute(['u'=>$uid]);$u=$q->fetch();
        if(!$u||$u['status']!=='ativo')throw new RuntimeException('Pagador indisponivel.');
        if($u['proprietario_id']!==null&&!$u['dados_completos'])throw new RuntimeException('Complete os Dados Cadastrais antes de iniciar pagamentos.');
        $doc=(string)($u['proprietario_id']!==null?$u['documento_proprietario']:$u['documento_usuario']);
        $type=(string)($u['proprietario_id']!==null?$u['tipo_proprietario']:$u['tipo_documento_usuario']);
        if(!AsaasSubcontaService::documentoValido($doc,$type))throw new RuntimeException('Informe CPF/CNPJ valido em seus dados antes de pagar.');
        $q=$this->db->prepare("SELECT * FROM asaas_customers WHERE usuario_id=:u AND ambiente='sandbox' AND conta_gateway=:c");$q->execute(['u'=>$uid,'c'=>$this->scope]);$existing=$q->fetch();
        if($existing){if(!hash_equals($existing['documento_hash'],hash('sha256',$doc)))throw new RuntimeException('Documento do pagador alterado; reconciliacao obrigatoria.');return ['id'=>$existing['asaas_customer_id']];}
        $payload=['name'=>$u['nome_razao_social']??$u['nome'],'cpfCnpj'=>$doc,'email'=>$u['email_financeiro']??$u['email'],'mobilePhone'=>preg_replace('/\D/','',(string)($u['celular']??$u['telefone'])),'notificationDisabled'=>true];
        foreach(['postalCode'=>'cep','address'=>'endereco','addressNumber'=>'numero','province'=>'bairro'] as $remote=>$local)if(!empty($u[$local]))$payload[$remote]=$u[$local];
        $result=(new FinancialOperationService($this->scope,$this->db))->executar('customer','usuario:'.$uid,1,$payload,
            function(string $ref,array $p)use($uid):array {
                $matches=[];
                // Include the old reference: absence of a local ID is not absence of an old customer.
                foreach([$ref,'usuario_'.$uid] as $reference){$offset=0;do{$r=$this->client->listarClientes(['externalReference'=>$reference,'limit'=>100,'offset'=>$offset]);foreach($r['data']??[] as $x){if(($x['externalReference']??'')!==$reference)continue;if(preg_replace('/\D/','',(string)($x['cpfCnpj']??''))!==$p['cpfCnpj'])throw new RuntimeException('Referencia externa com documento divergente.');$matches[$x['id']]=$x;}$offset+=100;}while(!empty($r['hasMore']));}
                return array_values($matches);
            },fn(string $ref,array $p):array=>$this->client->criarCliente($p+['externalReference'=>$ref]));
        $this->db->prepare("INSERT INTO asaas_customers(usuario_id,ambiente,conta_gateway,asaas_customer_id,documento_hash,operacao_id) VALUES(:u,'sandbox',:c,:id,:h,:o) ON CONFLICT(usuario_id,ambiente,conta_gateway) DO NOTHING")->execute(['u'=>$uid,'c'=>$this->scope,'id'=>$result['id'],'h'=>hash('sha256',$doc),'o'=>$result['_operation_id']]);
        return $result;
    }
}
