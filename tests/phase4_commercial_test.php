<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
use App\Services\CommercialConfigurationService;
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Somente banco local.');
$db=Database::getConnection();$service=new CommercialConfigurationService($db);$initial=$service->global();$tag=bin2hex(random_bytes(5));$users=[];$owner=$property=$affiliate=0;
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $f,string $m)use($check):void{try{$f();}catch(PDOException $e){throw $e;}catch(RuntimeException){$check(true,$m);return;}throw new RuntimeException($m);};
try {
    foreach(['admin','proprietario'] as $role){$q=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Commercial fixture',:e,'unused',:r,'ativo') RETURNING id");$q->execute(['e'=>$role.'-'.$tag.'@example.test','r'=>$role]);$users[$role]=(int)$q->fetchColumn();}
    $q=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Commercial fixture',:e,'ativo') RETURNING id");$q->execute(['u'=>$users['proprietario'],'e'=>'owner-'.$tag.'@example.test']);$owner=(int)$q->fetchColumn();
    $q=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional) VALUES(:p,'Commercial fixture','Sao Paulo','Teste',100,'disponivel','aprovada','disponivel') RETURNING id");$q->execute(['p'=>$owner]);$property=(int)$q->fetchColumn();
    $q=$db->prepare("INSERT INTO afiliados(nome,email,cpf_cnpj,telefone,senha_hash,status,chave_pix,tipo_chave_pix) VALUES('Commercial fixture',:e,'11144477735','11999998888','unused','ativo',:e,'email') RETURNING id");$q->execute(['e'=>'affiliate-'.$tag.'@example.test']);$affiliate=(int)$q->fetchColumn();
    $db->prepare("UPDATE proprietarios SET afiliado_id=:a,afiliado_origem='codigo',afiliado_atribuido_em=clock_timestamp() WHERE id=:p")->execute(['a'=>$affiliate,'p'=>$owner]);
    $reject(fn()=>$service->configureProperty($property,false,1000,$users['proprietario'],'teste'),'Proprietario nao altera comissao');
    $service->configureProperty($property,false,1000,$users['admin'],'teste');
    $service->configureGlobal(true,10000,$users['admin'],'teste');$service->configureAffiliate($affiliate,2000,$users['admin'],'teste');
    $first=$service->monthlyObligation($property,'2031-01-10');$check((int)$first['valor_centavos']===10000&&(int)$first['comissao_afiliado_centavos']===2000,'Mensalidade usa valor global e percentual individual');
    $same=$service->monthlyObligation($property,'2031-01-10');$check($same['id']===$first['id'],'Obrigacao por imovel e vencimento idempotente');
    $service->configureGlobal(true,20000,$users['admin'],'teste');$service->configureAffiliate($affiliate,0,$users['admin'],'teste');
    $frozen=$service->monthlyObligation($property,'2031-01-10');$check((int)$frozen['valor_centavos']===10000&&(int)$frozen['percentual_afiliado_bps']===2000,'Alteracao futura preserva snapshot anterior');
    $next=$service->monthlyObligation($property,'2031-02-10');$check((int)$next['valor_centavos']===20000&&(int)$next['comissao_afiliado_centavos']===0,'Nova obrigacao admite comissao individual zero');
    $current=$service->monthlyObligation($property,date('Y-m-d'));
    $q=$db->prepare("INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status) VALUES(:c,:p,TRUE,20000,'PENDENTE') RETURNING id");$q->execute(['c'=>$property,'p'=>$owner]);$monthly=(int)$q->fetchColumn();$paymentId='fixture-monthly-'.$tag;
    $db->prepare('UPDATE obrigacoes_mensalidades SET asaas_payment_id=:p WHERE id=:id')->execute(['p'=>$paymentId,'id'=>$current['id']]);
    $db->prepare("INSERT INTO cobrancas_mensalidades(mensalidade_id,obrigacao_id,asaas_payment_id,valor_centavos,status,vencimento) VALUES(:m,:o,:p,20000,'PENDENTE',CURRENT_DATE)")->execute(['m'=>$monthly,'o'=>$current['id'],'p'=>$paymentId]);
    $public=function()use($db,$property):bool{$q=$db->prepare('SELECT EXISTS(SELECT 1 FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id JOIN usuarios u ON u.id=p.usuario_id WHERE c.id=:id AND '.App\Models\Chacara::clausulaElegibilidadePublica().')');$q->execute(['id'=>$property]);return(bool)$q->fetchColumn();};
    $check(!$public(),'Aprovacao com mensalidade pendente nao publica');
    $service->configureAffiliate($affiliate,3500,$users['admin'],'teste posterior a emissao');
    $billing=new App\Services\MonthlyBillingService($db);$event=['asaas_event_id'=>'fixture-event-'.$tag];$payment=['id'=>$paymentId,'value'=>200,'paymentDate'=>date('Y-m-d')];
    $billing->processPayment($event,$payment,'PAYMENT_CONFIRMED');$billing->processPayment($event,$payment,'PAYMENT_CONFIRMED');
    $q=$db->prepare('SELECT COUNT(*) AS quantidade,MAX(percentual_bps) AS bps,MAX(valor_comissao_centavos) AS valor FROM comissoes_afiliados WHERE chacara_id=:c');$q->execute(['c'=>$property]);$ledger=$q->fetch();
    $check((int)$ledger['quantidade']===1&&(int)$ledger['bps']===0&&(int)$ledger['valor']===0,'Webhook repetido preserva snapshot zero no ledger sem duplicar');
    $check($public(),'Pagamento confirmado publica imovel aprovado e operacional');
    $db->prepare("UPDATE chacaras SET status_aprovacao='bloqueada' WHERE id=:id")->execute(['id'=>$property]);$check(!$public(),'Pagamento nao contorna bloqueio administrativo');$db->prepare("UPDATE chacaras SET status_aprovacao='aprovada' WHERE id=:id")->execute(['id'=>$property]);
    try{$db->prepare('UPDATE obrigacoes_mensalidades SET valor_centavos=999 WHERE id=:id')->execute(['id'=>$first['id']]);throw new RuntimeException('Snapshot alterado');}catch(PDOException $e){$check($e->getCode()==='23514','Banco impede reescrever snapshot');}
    $service->configureProperty($property,true,1000,$users['admin'],'teste');$check($service->monthlyObligation($property,'2031-03-10')===null,'Imovel de afiliado pode ser isento de mensalidade');
    $service->configureProperty($property,false,1000,$users['admin'],'teste');$service->configureGlobal(false,20000,$users['admin'],'teste');$check($service->monthlyObligation($property,'2031-03-10')===null,'Mensalidade global inativa nao gera obrigacao');
    $reject(fn()=>$service->configureAffiliate($affiliate,10001,$users['admin'],'teste'),'Percentual acima de 100 rejeitado');
    $reject(fn()=>$service->configureProperty($property,false,-1,$users['admin'],'teste'),'Percentual negativo rejeitado');
} finally {
    if($db->inTransaction())$db->rollBack();
    $db->prepare('UPDATE configuracoes_mensalidades_anuncios SET ativa=:a,valor_padrao_centavos=:v,versao=:version,atualizado_por=:u,atualizada_em=:d WHERE id=1')->execute(['a'=>$initial['ativa'],'v'=>$initial['valor_padrao_centavos'],'version'=>$initial['versao'],'u'=>$initial['atualizado_por'],'d'=>$initial['atualizada_em']]);
    if($property){$db->prepare('DELETE FROM comissoes_afiliados WHERE chacara_id=:id')->execute(['id'=>$property]);$db->prepare('DELETE FROM cobrancas_mensalidades WHERE mensalidade_id IN (SELECT id FROM mensalidades_anuncios WHERE chacara_id=:id)')->execute(['id'=>$property]);$db->prepare('DELETE FROM mensalidades_anuncios WHERE chacara_id=:id')->execute(['id'=>$property]);$db->prepare('DELETE FROM obrigacoes_mensalidades WHERE chacara_id=:id')->execute(['id'=>$property]);$db->prepare('DELETE FROM configuracoes_comerciais_imoveis WHERE chacara_id=:id')->execute(['id'=>$property]);$db->prepare('DELETE FROM chacaras WHERE id=:id')->execute(['id'=>$property]);}
    if($owner)$db->prepare('DELETE FROM proprietarios WHERE id=:id')->execute(['id'=>$owner]);
    if($affiliate)$db->prepare('DELETE FROM afiliados WHERE id=:id')->execute(['id'=>$affiliate]);
    foreach($users as $id){$db->prepare('DELETE FROM historico_condicoes_comerciais WHERE administrador_id=:id')->execute(['id'=>$id]);$db->prepare('DELETE FROM usuarios WHERE id=:id')->execute(['id'=>$id]);}
}
echo "Fase 4: configuracoes e snapshots locais verificados, fixtures removidas.\n";
