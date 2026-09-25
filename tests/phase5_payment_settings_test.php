<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
use App\Services\PropertyPaymentConfigurationService;
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Somente banco local.');
$db=Database::getConnection();$service=new PropertyPaymentConfigurationService($db);$original=$service->limits();$users=[];$owner=$property=0;$tag=bin2hex(random_bytes(5));
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $f,string $m)use($check):void{try{$f();}catch(PDOException $e){throw $e;}catch(RuntimeException){$check(true,$m);return;}throw new RuntimeException($m);};
try {
 foreach(['admin','proprietario'] as $role){$q=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Payment settings fixture',:e,'unused',:r,'ativo') RETURNING id");$q->execute(['e'=>$role.'-'.$tag.'@example.test','r'=>$role]);$users[$role]=(int)$q->fetchColumn();}
 $q=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Payment settings fixture',:e,'ativo') RETURNING id");$q->execute(['u'=>$users['proprietario'],'e'=>'owner-'.$tag.'@example.test']);$owner=(int)$q->fetchColumn();
 $q=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional) VALUES(:p,'Payment settings fixture','Sao Paulo','Teste',100,'disponivel','pendente','disponivel') RETURNING id");$q->execute(['p'=>$owner]);$property=(int)$q->fetchColumn();
 $reject(fn()=>$service->saveLimits(3000,7000,$users['proprietario']),'Proprietario nao define faixa global');
 $service->saveLimits(3000,7000,$users['admin']);$check($service->limits()['entrada_minima_bps']===3000,'Administrador define faixa global');
 $reject(fn()=>$service->saveOwner($property,$users['admin'],true,5000,0),'Identidade sem ownership nao define entrada');
 $reject(fn()=>$service->saveOwner($property,$users['proprietario'],true,2900,0),'Entrada abaixo do minimo recusada');
 $reject(fn()=>$service->saveOwner($property,$users['proprietario'],true,7100,0),'Entrada acima do maximo recusada');
 $service->saveOwner($property,$users['proprietario'],true,5000,0);$s=$service->effective($property);$check($s['aceita_parcelamento']&&$s['entrada_bps']===5000,'Proprietario escolhe entrada dentro da faixa');
 $reject(fn()=>$service->saveOwner($property,$users['proprietario'],false,null,0),'Formulario obsoleto nao sobrescreve configuracao');
 $service->saveLimits(3000,4000,$users['admin']);$s=$service->effective($property);$check(!$s['aceita_parcelamento']&&$s['entrada_bps']===5000,'Nova faixa invalida nova oferta sem alterar escolha anterior');
 $service->saveOwner($property,$users['proprietario'],true,3000,$s['versao_imovel']);$s=$service->effective($property);$check($s['aceita_parcelamento'],'Entrada ajustada restaura oferta de parcelamento');
 $service->saveOwner($property,$users['proprietario'],false,null,$s['versao_imovel']);$s=$service->effective($property);$check(!$s['aceita_parcelamento']&&$s['entrada_bps']===null,'Desativacao preserva pagamento integral e limpa entrada ativa');
 $reject(fn()=>$service->saveLimits(5000,4000,$users['admin']),'Faixa invertida recusada');
 try{$db->prepare('UPDATE configuracoes_comerciais_imoveis SET aceita_parcelamento=TRUE,entrada_bps=NULL WHERE chacara_id=:id')->execute(['id'=>$property]);throw new RuntimeException('Entrada nula aceita');}catch(PDOException $e){$check($e->getCode()==='23514','Banco rejeita parcelamento sem entrada');}
}finally{
 if($db->inTransaction())$db->rollBack();$db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max,versao=:v,atualizado_por=:u,atualizado_em=:t WHERE id=1')->execute(['min'=>$original['entrada_minima_bps'],'max'=>$original['entrada_maxima_bps'],'v'=>$original['versao'],'u'=>$original['atualizado_por'],'t'=>$original['atualizado_em']]);
 foreach($users as $id)$db->prepare('DELETE FROM historico_condicoes_pagamento WHERE usuario_id=:id')->execute(['id'=>$id]);
 if($property){$db->prepare('DELETE FROM configuracoes_comerciais_imoveis WHERE chacara_id=:id')->execute(['id'=>$property]);$db->prepare('DELETE FROM chacaras WHERE id=:id')->execute(['id'=>$property]);}if($owner)$db->prepare('DELETE FROM proprietarios WHERE id=:id')->execute(['id'=>$owner]);foreach($users as $id)$db->prepare('DELETE FROM usuarios WHERE id=:id')->execute(['id'=>$id]);
}
echo "Fase 5: limites, ownership e versoes verificados; fixtures removidas.\n";
