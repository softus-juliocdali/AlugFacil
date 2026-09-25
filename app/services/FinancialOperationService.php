<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/** Durable intent + session advisory lock. Unknown outcomes are reconciled, never blindly POSTed. */
final class FinancialOperationService
{
    private static int $outboundDepth=0;
    private static ?array $outboundContext=null;
    public static function allowsMutation():bool{return self::$outboundDepth>0;}
    public static function outboundContext():?array{return self::$outboundContext;}
    private string $instance;
    public function __construct(private string $scope,private ?PDO $db=null)
    {
        $this->db??=Database::getConnection();
        if($scope==='')throw new RuntimeException('Conta gateway obrigatoria.');
        $this->instance=substr(hash('sha256',(string)getenv('APP_URL').'|'.(string)getenv('DB_NAME')),0,32);
    }

    public function executar(string $type,string $entity,int $version,array $payload,callable $find,callable $create,?callable $beforeSend=null):array
    {
        if($this->db->inTransaction())throw new RuntimeException('Operacoes externas exigem intencao ja confirmada fora de transacao de negocio.');
        self::rejectSecrets($payload);
        $hash=hash('sha256',self::canonicalJson($payload));
        $key=['a'=>'sandbox','c'=>$this->scope,'i'=>$this->instance,'t'=>$type,'e'=>$entity,'v'=>$version];
        $reference='af_'.$this->instance.'_'.substr(hash('sha256',implode('|',$key)),0,40);
        $s=$this->db->prepare('INSERT INTO operacoes_financeiras(ambiente,conta_gateway,instancia,tipo,entidade,versao_obrigacao,referencia,request_hash,payload) VALUES(:a,:c,:i,:t,:e,:v,:r,:h,CAST(:p AS jsonb)) ON CONFLICT(ambiente,conta_gateway,instancia,tipo,entidade,versao_obrigacao) DO NOTHING');
        $s->execute($key+['r'=>$reference,'h'=>$hash,'p'=>self::canonicalJson($payload)]);
        $s=$this->db->prepare('SELECT * FROM operacoes_financeiras WHERE referencia=:r');$s->execute(['r'=>$reference]);$row=$s->fetch();$id=(int)$row['id'];
        $lock=$this->db->prepare('SELECT pg_try_advisory_lock(741031,:id)');$lock->execute(['id'=>$id]);
        if(!$lock->fetchColumn())throw new RuntimeException('Operacao financeira em andamento.');
        try {
            $s->execute(['r'=>$reference]);$row=$s->fetch();
            if(!hash_equals($row['request_hash'],$hash))throw new RuntimeException('Intencao financeira existente com payload diferente; concilie antes de alterar.');
            if($row['estado']==='concluida')return json_decode($row['resultado'],true,512,JSON_THROW_ON_ERROR)+['_operation_id'=>$id];
            if($row['estado']==='cancelada')throw new RuntimeException('Operacao cancelada.');
            if($row['estado']==='recusada'&&(int)$row['http_status']!==429)throw new RuntimeException('Recusa definitiva registrada. Corrija a causa e registre uma nova obrigacao antes de outro envio.');
            if($row['proxima_tentativa_em'] && strtotime($row['proxima_tentativa_em'])>time())throw new RuntimeException('Aguarde a proxima tentativa financeira.');
            // A process may have died after posting. Even an empty GET does not prove that POST failed.
            $unknown=in_array($row['estado'],['executando','desconhecida'],true);
            $matches=$find($reference,$payload);
            if(!is_array($matches))throw new RuntimeException('Consulta de reconciliacao invalida.');
            if(count($matches)>1){$this->state($id,'desconhecida','MULTIPLAS_CORRESPONDENCIAS');throw new RuntimeException('Multiplos recursos externos; conciliacao necessaria.');}
            if(count($matches)===1)return $this->complete($id,$matches[0],'reconciliacao');
            if($unknown){$this->state($id,'desconhecida','RESULTADO_DESCONHECIDO');throw new RuntimeException('Resultado externo desconhecido; POST nao repetido.');}
            // A failed local eligibility/balance check is not an unknown remote write.
            if($beforeSend!==null)$beforeSend($reference,$payload);
            $this->db->prepare("UPDATE operacoes_financeiras SET estado='executando',tentativas=tentativas+1,atualizada_em=clock_timestamp() WHERE id=:id")->execute(['id'=>$id]);
            $this->history($id,'envio','iniciado');
            try {
                self::$outboundDepth++;
                $previousContext=self::$outboundContext;
                self::$outboundContext=['type'=>$type,'entity'=>$entity,'reference'=>$reference];
                try{$out=$create($reference,$payload);}finally{self::$outboundDepth--;self::$outboundContext=$previousContext;}
                if(!is_array($out)||trim((string)($out['id']??''))==='')throw new AsaasTimeoutException('Resposta sem identificador; resultado desconhecido.');
                return $this->complete($id,$out,'envio');
            }catch(AsaasApiException $e){
                $code=(int)$e->getCode();$state=in_array($code,[400,401,403,404,422,429],true)?'recusada':'desconhecida';
                $this->state($id,$state,'HTTP_'.$code.'_'.$e->gatewayCode,$code);throw new RuntimeException('Asaas retornou HTTP '.$code.'. Operacao registrada para acompanhamento.');
            }catch(Throwable $e){$this->state($id,'desconhecida','RESULTADO_DESCONHECIDO');throw new RuntimeException('Resultado externo desconhecido. Conciliacao obrigatoria antes de repetir.');}
        } finally {$this->db->prepare('SELECT pg_advisory_unlock(741031,:id)')->execute(['id'=>$id]);}
    }

    private function complete(int $id,array $response,string $action):array
    {
        if(empty($response['id']))throw new RuntimeException('Recurso remoto sem identificador.');
        $safe=array_intersect_key($response,array_flip(['id','walletId','status','invoiceUrl','link','url','dueDate','externalReference','value','netValue','customer','billingType','deleted']));
        $this->db->beginTransaction();try {
            $this->db->prepare("UPDATE operacoes_financeiras SET estado='concluida',resultado=CAST(:r AS jsonb),erro_codigo=NULL,proxima_tentativa_em=NULL,atualizada_em=clock_timestamp() WHERE id=:id")->execute(['r'=>json_encode($safe,JSON_THROW_ON_ERROR),'id'=>$id]);
            if(isset($response['_http_status']))$this->db->prepare('UPDATE operacoes_financeiras SET http_status=:http WHERE id=:id')->execute(['http'=>(int)$response['_http_status'],'id'=>$id]);
            $this->history($id,$action,'concluida',isset($response['_http_status'])?(int)$response['_http_status']:null);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $safe+['_operation_id'=>$id];
    }
    private function state(int $id,string $state,string $code,?int $http=null):void
    {
        $this->db->prepare("UPDATE operacoes_financeiras SET estado=:s,erro_codigo=:c,http_status=:h,proxima_tentativa_em=CASE WHEN :retry THEN clock_timestamp()+INTERVAL '60 seconds' ELSE NULL END,atualizada_em=clock_timestamp() WHERE id=:id")->execute(['s'=>$state,'retry'=>$state==='recusada'&&$http===429,'c'=>$code,'h'=>$http,'id'=>$id]);
        $this->history($id,'resultado',$state,$http);
    }
    private function history(int $id,string $action,string $result,?int $http=null):void
    {$this->db->prepare('INSERT INTO tentativas_operacoes_financeiras(operacao_id,acao,resultado,http_status) VALUES(:id,:a,:r,:h)')->execute(['id'=>$id,'a'=>$action,'r'=>$result,'h'=>$http]);}
    private static function canonicalJson(array $data):string
    {if(!array_is_list($data))ksort($data);foreach($data as &$v)if(is_array($v))$v=json_decode(self::canonicalJson($v),true,512,JSON_THROW_ON_ERROR);unset($v);return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function rejectSecrets(array $data):void
    {foreach($data as $k=>$v){if(preg_match('/^(apiKey|access_token|authorization|proxy-authorization|password|senha|creditCard|creditCardToken|creditCardNumber|cardNumber|ccv|cvv|securityCode|webhooks)$/i',(string)$k))throw new RuntimeException('Segredo ou dados de cartao nao podem compor intencao financeira.');if(is_array($v))self::rejectSecrets($v);}}
}
