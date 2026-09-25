<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use DateTimeImmutable;use PDO;use RuntimeException;use Throwable;
final class AsaasSubcontaService
{
 public const ACEITE_TEXTO='Autorizo o envio dos dados cadastrais ao Asaas e a criacao de uma conta Asaas vinculada. Estou ciente de que receberei comunicacoes do Asaas, acessarei a conta diretamente no Asaas e o recebimento depende de aprovacao cadastral. Texto operacional sujeito a revisao juridica.';
 public function __construct(private AsaasClientInterface $client,private ?PDO $db=null){$this->db??=Database::getConnection();}
 public static function validarDados(array $in):array
 {
  $d=[];foreach(['tipo_pessoa','cpf_cnpj','nome_razao_social','nome_fantasia','data_nascimento','tipo_empresa','telefone','celular','email_financeiro','cep','endereco','numero','complemento','bairro','cidade','estado']as$k)$d[$k]=trim((string)($in[$k]??''));
  $d['tipo_pessoa']=strtoupper($d['tipo_pessoa']);$d['cpf_cnpj']=preg_replace('/\D+/','',$d['cpf_cnpj'])??'';$d['cep']=preg_replace('/\D+/','',$d['cep'])??'';$d['telefone']=preg_replace('/\D+/','',$d['telefone'])??'';$d['celular']=preg_replace('/\D+/','',$d['celular'])??'';$d['estado']=strtoupper($d['estado']);
  $raw=(string)($in['renda_faturamento_mensal']??'');$raw=str_replace(['.',' '],'',$raw);$raw=str_replace(',','.',$raw);if(!is_numeric($raw))throw new RuntimeException('Informe renda ou faturamento valido.');$d['renda_faturamento_mensal_centavos']=(int)round((float)$raw*100);
  if(!in_array($d['tipo_pessoa'],['PF','PJ'],true))throw new RuntimeException('Tipo de pessoa invalido.');if(!self::documentoValido($d['cpf_cnpj'],$d['tipo_pessoa']))throw new RuntimeException($d['tipo_pessoa']==='PF'?'CPF invalido.':'CNPJ invalido.');
  if($d['nome_razao_social']===''||!filter_var($d['email_financeiro'],FILTER_VALIDATE_EMAIL)||strlen($d['cep'])!==8||strlen($d['celular'])<10||strlen($d['celular'])>11||$d['endereco']===''||$d['numero']===''||$d['bairro']===''||$d['cidade']===''||!preg_match('/^[A-Z]{2}$/',$d['estado'])||$d['renda_faturamento_mensal_centavos']<=0)throw new RuntimeException('Dados financeiros incompletos ou invalidos.');
  if($d['telefone']!==''&&(strlen($d['telefone'])<10||strlen($d['telefone'])>11))throw new RuntimeException('Telefone invalido.');
  if($d['tipo_pessoa']==='PF'){if(!self::dataNascimentoValida($d['data_nascimento']))throw new RuntimeException('Data de nascimento invalida ou incompativel.');$d['tipo_empresa']='';}else{if(!in_array($d['tipo_empresa'],['MEI','LIMITED','INDIVIDUAL','ASSOCIATION'],true))throw new RuntimeException('Tipo de empresa invalido.');$d['data_nascimento']='';}
  return$d;
 }
 public static function aceiteHash():string{return hash('sha256',self::ACEITE_TEXTO);}
 public function payload(array $d):array{$p=['name'=>$d['nome_razao_social'],'email'=>$d['email_financeiro'],'loginEmail'=>$d['email_financeiro'],'cpfCnpj'=>$d['cpf_cnpj'],'phone'=>$d['telefone']?:null,'mobilePhone'=>$d['celular'],'incomeValue'=>$d['renda_faturamento_mensal_centavos']/100,'address'=>$d['endereco'],'addressNumber'=>$d['numero'],'complement'=>$d['complemento']?:null,'province'=>$d['bairro'],'postalCode'=>$d['cep']];if($d['tipo_pessoa']==='PF')$p['birthDate']=$d['data_nascimento'];else$p['companyType']=$d['tipo_empresa'];return array_filter($p,static fn($v)=>$v!==null&&$v!=='');}
 public function criar(int $pid,int $adminId,string $motivo,bool $confirmado):array
 {return (new ProvisionamentoAsaasService($this->client,$this->db))->processar($pid);}
 public function sincronizar(int $pid,?int $adminId=null,string $motivo='Sincronizacao solicitada'):string
 {return (new ProvisionamentoAsaasService($this->client,$this->db))->sincronizar($pid)['status_local'];}
 public function conciliar(int $pid,int $adminId,string $motivo):bool
 {(new ProvisionamentoAsaasService($this->client,$this->db))->processar($pid);return true;}
 public function financeiramenteHabilitado(int $pid):bool{return self::isFinanciallyEnabled($this->db,$pid);}
 public static function isFinanciallyEnabled(PDO $db,int $pid):bool{$s=$db->prepare("SELECT EXISTS(SELECT 1 FROM proprietarios p JOIN usuarios u ON u.id=p.usuario_id JOIN proprietario_cadastro d ON d.proprietario_id=p.id JOIN asaas_subcontas a ON a.proprietario_id=p.id AND a.ambiente='sandbox' WHERE p.id=:p AND u.status='ativo' AND d.dados_completos AND a.asaas_wallet_id IS NOT NULL AND a.status_local='aprovada' AND a.status_operacional_asaas='ENABLED' AND EXISTS(SELECT 1 FROM aceites_financeiros_proprietarios x WHERE x.proprietario_id=p.id AND x.tipo_aceite='ONBOARDING_ASAAS_NON_BAAS' AND x.revogado_em IS NULL))");$s->execute(['p'=>$pid]);return(bool)$s->fetchColumn();}
 public static function documentoValido(string$d,string$t):bool{$len=$t==='PF'?11:14;if(strlen($d)!==$len||preg_match('/^(\d)\1+$/',$d))return false;if($t==='PF'){$sum=0;for($i=0;$i<9;$i++)$sum+=(int)$d[$i]*(10-$i);$x=($sum*10)%11;if($x===10)$x=0;if($x!==(int)$d[9])return false;$sum=0;for($i=0;$i<10;$i++)$sum+=(int)$d[$i]*(11-$i);$x=($sum*10)%11;if($x===10)$x=0;return$x===(int)$d[10];}$weights=[[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]];for($digit=12;$digit<14;$digit++){$sum=0;for($i=0;$i<$digit;$i++)$sum+=(int)$d[$i]*$weights[$digit-12][$i];$x=$sum%11;$x=$x<2?0:11-$x;if($x!==(int)$d[$digit])return false;}return true;}
 private static function dataNascimentoValida(string$d):bool{try{$date=new DateTimeImmutable($d);$today=new DateTimeImmutable('today');return$date->format('Y-m-d')===$d&&$date<=$today->modify('-18 years')&&$date>=$today->modify('-120 years');}catch(Throwable){return false;}}
}
