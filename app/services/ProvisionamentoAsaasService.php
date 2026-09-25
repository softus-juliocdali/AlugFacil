<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ProvisionamentoAsaasService
{
    private string $scope;
    public function __construct(private AsaasClientInterface $client,private ?PDO $db=null,?string $scope=null)
    {$this->db??=Database::getConnection();$this->scope=$scope??(method_exists($client,'accountScope')?$client->accountScope():throw new RuntimeException('Escopo da conta necessario.'));}

    public function processar(int $pid):array
    {
        AsaasEnvironment::assertCredentials(true);
        if(getenv('ASAAS_ENABLE_SUBACCOUNT_CREATION')!=='true'||(getenv('ASAAS_SUBACCOUNT_MODEL')?:'NON_BAAS')!=='NON_BAAS')throw new RuntimeException('Provisionamento desabilitado.');
        $q=$this->db->prepare("SELECT d.*,u.status usuario_status FROM proprietario_cadastro d JOIN proprietarios p ON p.id=d.proprietario_id JOIN usuarios u ON u.id=p.usuario_id WHERE d.proprietario_id=:p");$q->execute(['p'=>$pid]);$d=$q->fetch();
        if(!$d||!$d['dados_completos']||$d['situacao']!=='validado'||$d['usuario_status']!=='ativo')throw new RuntimeException('Cadastro incompleto, conflitante ou identidade bloqueada.');
        $q=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM aceites_financeiros_proprietarios WHERE proprietario_id=:p AND tipo_aceite='ONBOARDING_ASAAS_NON_BAAS' AND texto_hash=:h AND revogado_em IS NULL)");$q->execute(['p'=>$pid,'h'=>AsaasSubcontaService::aceiteHash()]);
        if(!$q->fetchColumn()){$this->fila($pid,'aguardando_aceite','ACEITE_PENDENTE');throw new RuntimeException('Autorizacao financeira pendente.');}
        $existing=$this->subconta($pid);
        if($existing&&!empty($existing['asaas_account_id']))return $this->sincronizar($pid);
        if($existing && !str_starts_with((string)$existing['solicitacao_id'],'operacao_'))throw new RuntimeException('Tentativa legada sem resultado reconciliado; nova criacao bloqueada.');
        $validated=AsaasSubcontaService::validarDados($d+['renda_faturamento_mensal'=>number_format((int)$d['renda_faturamento_mensal_centavos']/100,2,',','')]);
        $payload=(new AsaasSubcontaService($this->client,$this->db))->payload($validated);
        try {
            $out=(new FinancialOperationService($this->scope,$this->db))->executar('subconta','proprietario:'.$pid,1,$payload,
                function(string $ref,array $p):array {
                    $matches=[];$offset=0;
                    do{$r=$this->client->listarSubcontas(['cpfCnpj'=>$p['cpfCnpj'],'limit'=>100,'offset'=>$offset]);foreach($r['data']??[] as $x){if(preg_replace('/\D/','',(string)($x['cpfCnpj']??''))!==$p['cpfCnpj'])continue;if(strcasecmp((string)($x['email']??''),$p['email'])!==0)throw new RuntimeException('Documento com subconta de contato divergente; conciliacao obrigatoria.');if(empty($x['walletId']))throw new RuntimeException('Subconta existente sem wallet informada.');$matches[$x['id']]=$x;}$offset+=100;}while(!empty($r['hasMore']));return array_values($matches);
                },function(string $ref,array $p):array {
                    $root=$this->client->verificarContaRaiz();
                    if(strlen(preg_replace('/\D/','',(string)($root['cpfCnpj']??'')))!==14)throw new RuntimeException('Conta-pai PJ obrigatoria.');
                    $r=$this->client->criarSubconta($p);if(empty($r['walletId']))throw new AsaasTimeoutException('Subconta sem wallet; resultado desconhecido.');return $r;
                });
            $this->db->beginTransaction();
            $q=$this->db->prepare("INSERT INTO asaas_subcontas(proprietario_id,ambiente,modelo,asaas_account_id,asaas_wallet_id,email_cadastro,status_local,request_hash,solicitacao_id,quantidade_tentativas,criada_em) VALUES(:p,'sandbox','NON_BAAS',:a,:w,:e,'aguardando_ativacao',:h,:s,1,CURRENT_TIMESTAMP) ON CONFLICT(proprietario_id,ambiente) DO UPDATE SET asaas_account_id=EXCLUDED.asaas_account_id,asaas_wallet_id=EXCLUDED.asaas_wallet_id,status_local='aguardando_ativacao',atualizado_em=CURRENT_TIMESTAMP RETURNING id");$q->execute(['p'=>$pid,'a'=>$out['id'],'w'=>$out['walletId'],'e'=>$d['email_financeiro'],'h'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),'s'=>'operacao_'.$out['_operation_id']]);$id=(int)$q->fetchColumn();
            $this->db->prepare("INSERT INTO historico_asaas_subcontas(asaas_subconta_id,proprietario_id,status_novo,origem,motivo) VALUES(:id,:p,'aguardando_ativacao','sistema','Provisionamento automatico Sandbox confirmado')")->execute(['id'=>$id,'p'=>$pid]);$this->db->commit();
            $this->fila($pid,'aguardando_asaas',null);
            return $this->sincronizar($pid);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$q=$this->db->prepare("SELECT estado,http_status,erro_codigo FROM operacoes_financeiras WHERE tipo='subconta' AND entidade=:e AND conta_gateway=:c ORDER BY id DESC LIMIT 1");$q->execute(['e'=>'proprietario:'.$pid,'c'=>$this->scope]);$op=$q->fetch()?:[];$state=$op['estado']??null;$unknown=in_array($state,['executando','desconhecida'],true);$refused=$state==='recusada'&&(int)($op['http_status']??0)!==429;$this->fila($pid,$unknown||$refused?'conciliacao_manual':'pendente',$unknown?'RESULTADO_DESCONHECIDO':($refused?($op['erro_codigo']??'RECUSA_DEFINITIVA'):'ASAAS_PENDENTE'));throw $e;}
    }

    public function sincronizar(int $pid):array
    {
        $row=$this->subconta($pid);if(!$row||empty($row['asaas_account_id']))throw new RuntimeException('Subconta ainda nao vinculada.');
        // Reading the linked account through the parent also checks that it belongs to this gateway account.
        $remote=$this->client->consultarSubconta($row['asaas_account_id']);
        if(($remote['id']??'')!==$row['asaas_account_id']||($remote['walletId']??'')!==$row['asaas_wallet_id'])throw new RuntimeException('Subconta divergente da conta-pai.');
        if(!method_exists($this->client,'consultarStatusSubconta'))throw new RuntimeException('Consulta oficial de KYC indisponivel.');
        $status=$this->client->consultarStatusSubconta($row['asaas_account_id']);
        $general=(string)($status['general']??'PENDING');$commercial=(string)($status['commercialInfo']??'PENDING');
        $local=match($general){'APPROVED'=>'aprovada','REJECTED'=>'rejeitada',default=>'em_analise'};
        // An expired/incomplete commercial registration cannot be promoted as financially enabled.
        $operational=$general==='APPROVED'&&$commercial==='APPROVED'?'ENABLED':'PENDING';
        $this->db->beginTransaction();try {
            $this->db->prepare('UPDATE asaas_subcontas SET status_local=:s,status_cadastral_asaas=:g,status_operacional_asaas=:o,ultima_sincronizacao_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['s'=>$local,'g'=>$general,'o'=>$operational,'id'=>$row['id']]);
            if($local!==$row['status_local'])$this->db->prepare("INSERT INTO historico_asaas_subcontas(asaas_subconta_id,proprietario_id,status_anterior,status_novo,origem,motivo) VALUES(:id,:p,:a,:n,'sincronizacao','Situacao cadastral consultada no Asaas')")->execute(['id'=>$row['id'],'p'=>$pid,'a'=>$row['status_local'],'n'=>$local]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $this->fila($pid,$operational==='ENABLED'?'concluido':'aguardando_asaas',null);return $this->subconta($pid);
    }
    private function subconta(int $pid):?array {$q=$this->db->prepare("SELECT * FROM asaas_subcontas WHERE proprietario_id=:p AND ambiente='sandbox'");$q->execute(['p'=>$pid]);return $q->fetch()?:null;}
    private function fila(int $pid,string $state,?string $error):void {$this->db->prepare("UPDATE onboarding_fila SET estado=:s,erro_codigo=:e,tentativas=tentativas+1,proxima_tentativa_em=clock_timestamp()+INTERVAL '5 minutes',atualizado_em=clock_timestamp() WHERE proprietario_id=:p")->execute(['s'=>$state,'e'=>$error,'p'=>$pid]);}
}
