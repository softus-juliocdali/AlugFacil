<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
use App\Services\AffiliateIdentityService;
use App\Services\ReservationAuthorization;
if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true)||getenv('DB_NAME')!=='alugfacil_dev')throw new RuntimeException('Somente banco local.');
$db=Database::getConnection();$ids=[];$owners=[];$cid=$aid=0;$reservations=[];$tag=bin2hex(random_bytes(5));
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$reject=static function(callable $fn,string $message)use($check):void {try{$fn();}catch(DomainException|PDOException){$check(true,$message);return;}throw new RuntimeException($message);};
try {
    foreach(['cliente','proprietario','admin','proprietario'] as $i=>$role){$q=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Identity fixture',:e,:s,:r,'ativo') RETURNING id");$q->execute(['e'=>'identity-'.$i.'-'.$tag.'@example.test','s'=>password_hash('principal-test',PASSWORD_DEFAULT),'r'=>$role]);$ids[]=(int)$q->fetchColumn();if($role==='proprietario'){$q=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Identity fixture',:e,'ativo') RETURNING id");$q->execute(['u'=>$ids[$i],'e'=>'identity-'.$i.'-'.$tag.'@example.test']);$owners[$i]=(int)$q->fetchColumn();}}
    $q=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional) VALUES(:p,'Identity fixture','Sao Paulo','Rua Teste',100,'disponivel','aprovada','disponivel') RETURNING id");$q->execute(['p'=>$owners[3]]);$cid=(int)$q->fetchColumn();
    $policy=new ReservationAuthorization();
    foreach([0=>'Cliente',1=>'Proprietario de outro imovel',2=>'Admin'] as $i=>$label){$policy->assertCanBook($ids[$i],$cid);$check(true,$label.' pode reservar');}
    $reject(fn()=>$policy->assertCanBook($ids[3],$cid),'Proprietario nao reserva proprio imovel');
    $q=$db->prepare("INSERT INTO reservas(usuario_id,proprietario_id,chacara_id,data_inicio,data_fim,quantidade_diarias,valor_diaria,valor_total,status_reserva,status_pagamento) VALUES(:u,:p,:c,'2030-01-01','2030-01-02',1,100,100,'aguardando_pagamento','pendente') RETURNING id");
    $reject(fn()=>$q->execute(['u'=>$ids[3],'p'=>$owners[3],'c'=>$cid]),'Banco impede bypass da reserva propria');
    $reject(fn()=>$q->execute(['u'=>$ids[0],'p'=>$owners[1],'c'=>$cid]),'FK composta impede dono divergente do imovel');
    $q->execute(['u'=>$ids[0],'p'=>$owners[3],'c'=>$cid]);$reservations[]=(int)$q->fetchColumn();
    $policy->assertReservation($ids[0],$reservations[0],'reserva.pagar_proprias');$check(true,'Pagamento autorizado para titular');
    $reject(fn()=>$policy->assertReservation($ids[1],$reservations[0],'reserva.pagar_proprias'),'Pagamento de reserva alheia negado');
    $db->prepare("UPDATE usuarios SET status='bloqueado' WHERE id=:u")->execute(['u'=>$ids[0]]);$reject(fn()=>$policy->assertCanBook($ids[0],$cid),'Bloqueio revoga capacidade de hospede');$db->prepare("UPDATE usuarios SET status='ativo' WHERE id=:u")->execute(['u'=>$ids[0]]);
    $email='identity-0-'.$tag.'@example.test';
    $q=$db->prepare("INSERT INTO afiliados(nome,email,cpf_cnpj,telefone,senha_hash,status,chave_pix,tipo_chave_pix) VALUES('Afiliado fixture',:e,'11144477735','11999998888',:s,'ativo',:e,'email') RETURNING id");$q->execute(['e'=>$email,'s'=>password_hash('affiliate-test',PASSWORD_DEFAULT)]);$aid=(int)$q->fetchColumn();
    $service=new AffiliateIdentityService();$check($service->principal($aid)===null,'Emails iguais nao vinculam registros automaticamente');
    $reject(fn()=>$service->vincular($aid,'incorreta',$email,'principal-test'),'Senha do afiliado obrigatoria');
    $reject(fn()=>$service->vincular($aid,'affiliate-test',$email,'incorreta'),'Senha da identidade principal obrigatoria');
    $check($service->vincular($aid,'affiliate-test',$email,'principal-test')===$ids[0],'Duas credenciais comprovam o vinculo');
    $service->vincular($aid,'affiliate-test',$email,'principal-test');
    $check((int)$db->query('SELECT count(*) FROM historico_vinculos_afiliados WHERE afiliado_id='.$aid)->fetchColumn()===1,'Vinculo repetido idempotente');
    $principal=$service->principal($aid);$policy->assertCanBook((int)$principal['id'],$cid);$check(true,'Afiliado vinculado reserva com ID principal');
    $reject(fn()=>$service->vincular($aid,'affiliate-test','identity-1-'.$tag.'@example.test','principal-test'),'Vinculo nao pode ser trocado silenciosamente');
    $db->prepare("UPDATE afiliados SET status='bloqueado' WHERE id=:a")->execute(['a'=>$aid]);$check($service->principal($aid)===null,'Afiliado bloqueado nao autentica principal');
    $db->prepare("UPDATE afiliados SET status='ativo' WHERE id=:a")->execute(['a'=>$aid]);$db->prepare("UPDATE usuarios SET status='bloqueado' WHERE id=:u")->execute(['u'=>$ids[0]]);$check($service->principal($aid)===null,'Principal bloqueado nao e contornado pelo afiliado');
}finally{
    if($db->inTransaction())$db->rollBack();
    if($aid){$db->prepare('DELETE FROM historico_vinculos_afiliados WHERE afiliado_id=:a')->execute(['a'=>$aid]);$db->prepare('DELETE FROM afiliados WHERE id=:a')->execute(['a'=>$aid]);}
    foreach($reservations as $id)$db->prepare('DELETE FROM reservas WHERE id=:r')->execute(['r'=>$id]);
    if($cid)$db->prepare('DELETE FROM chacaras WHERE id=:c')->execute(['c'=>$cid]);
    foreach($owners as $id)$db->prepare('DELETE FROM proprietarios WHERE id=:p')->execute(['p'=>$id]);
    foreach($ids as $id)$db->prepare('DELETE FROM usuarios WHERE id=:u')->execute(['u'=>$id]);
}
echo "Fase 2 concluida no PostgreSQL real; fixtures removidas.\n";
