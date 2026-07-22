<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;

$db=Database::getConnection();
if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Smoke permitido somente em alugfacil_dev.');
$base='http://127.0.0.1:8000';$tag=bin2hex(random_bytes(4));$emails=['owner'=>"smoke-owner-$tag@localhost.test",'admin'=>"smoke-admin-$tag@localhost.test",'client'=>"smoke-client-$tag@localhost.test"];$ids=[];$ownerId=0;$checks=[];
$request=function(string$method,string$url,array$data=[],?string$cookie=null):array{$ch=curl_init($url);$headers=[];curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>10]);if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data));if($cookie){curl_setopt($ch,CURLOPT_COOKIEJAR,$cookie);curl_setopt($ch,CURLOPT_COOKIEFILE,$cookie);}$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$parts=preg_split("/\r?\n\r?\n/",(string)$raw,2);return[$status,$parts[1]??''];};
$login=function(string$email,string$cookie)use($request,$base):void{[, $body]=$request('GET',$base.'/login',[],$cookie);if(!preg_match('/name="_token" value="([^"]+)"/',$body,$m))throw new RuntimeException('CSRF de login ausente.');[$status]=$request('POST',$base.'/login',['_token'=>html_entity_decode($m[1]),'email'=>$email,'senha'=>'SmokeLocal123!'],$cookie);if($status!==302)throw new RuntimeException('Login falhou HTTP '.$status);};
try{
 foreach($emails as$role=>$email){$s=$db->prepare('INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES(:n,:e,:h,:r,\'ativo\') RETURNING id');$s->execute(['n'=>'Smoke Teste','e'=>$email,'h'=>password_hash('SmokeLocal123!',PASSWORD_DEFAULT),'r'=>match($role){'owner'=>'proprietario','client'=>'cliente',default=>'admin'}]);$ids[]=(int)$s->fetchColumn();}
 $s=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,cpf,status) VALUES(:u,'Smoke Teste',:e,NULL,'ativo') RETURNING id");$s->execute(['u'=>$ids[0],'e'=>$emails['owner']]);$ownerId=(int)$s->fetchColumn();
 foreach(['owner','admin','client']as$ix=>$role){$cookie=tempnam(sys_get_temp_dir(),'af_smoke_');$login($emails[$role],$cookie);if($role==='owner'){[$st,$body]=$request('GET',$base.'/proprietario/recebimentos',[],$cookie);$checks['proprietario_acessa']=$st===200&&str_contains($body,'Recebimentos');[$bad]=$request('POST',$base.'/proprietario/recebimentos/dados',['_token'=>'invalido'],$cookie);$q=$db->prepare('SELECT count(1) FROM proprietario_dados_financeiros WHERE proprietario_id=:p');$q->execute(['p'=>$ownerId]);$checks['csrf_invalido']=in_array($bad,[302,419],true)&&(int)$q->fetchColumn()===0;[$get]=$request('GET',$base.'/proprietario/recebimentos/dados',[],$cookie);$checks['get_nao_muta']=$get===404;}elseif($role==='admin'){[$st,$body]=$request('GET',$base.'/admin/onboarding-financeiro',[],$cookie);$checks['admin_lista']=$st===200&&str_contains($body,'Onboarding financeiro');[$st,$body]=$request('GET',$base.'/admin/onboarding-financeiro/'.$ownerId,[],$cookie);$checks['admin_detalhe']=$st===200&&!str_contains($body,'apiKey');}else{[$st]=$request('GET',$base.'/admin/onboarding-financeiro',[],$cookie);$checks['cliente_bloqueado']=$st===302;}@unlink($cookie);}
 foreach($checks as$n=>$ok)echo($ok?'[OK] ':'[FALHA] ').$n.PHP_EOL;
}finally{
 if($ownerId){$db->prepare('DELETE FROM auditoria_dados_financeiros WHERE proprietario_id=:p')->execute(['p'=>$ownerId]);$db->prepare('DELETE FROM aceites_financeiros_proprietarios WHERE proprietario_id=:p')->execute(['p'=>$ownerId]);$db->prepare('DELETE FROM proprietario_dados_financeiros WHERE proprietario_id=:p')->execute(['p'=>$ownerId]);$db->prepare('DELETE FROM proprietarios WHERE id=:p')->execute(['p'=>$ownerId]);}
 if($ids){$in=implode(',',array_map('intval',$ids));$db->exec("DELETE FROM usuarios WHERE id IN ($in)");}
}
exit(in_array(false,$checks,true)?1:0);
