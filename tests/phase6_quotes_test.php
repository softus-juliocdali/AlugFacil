<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;use App\Services\CheckoutQuoteService;
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Somente banco local.');
$db=Database::getConnection();$service=new CheckoutQuoteService($db);$users=[];$owner=$property=0;$tag=bin2hex(random_bytes(5));
$limits=$db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();$cfg=$db->query('SELECT * FROM configuracoes_financeiras WHERE vigencia_fim IS NULL')->fetch();
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$reject=static function(callable $f,string $m)use($check):void{try{$f();}catch(PDOException $e){throw $e;}catch(RuntimeException|DomainException){$check(true,$m);return;}throw new RuntimeException($m);};
try{
 foreach(['proprietario','cliente'] as $role){$q=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Quote fixture',:e,'unused',:r,'ativo') RETURNING id");$q->execute(['e'=>$role.'-'.$tag.'@example.test','r'=>$role]);$users[$role]=(int)$q->fetchColumn();}
 $q=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Quote fixture',:e,'ativo') RETURNING id");$q->execute(['u'=>$users['proprietario'],'e'=>'owner-'.$tag.'@example.test']);$owner=(int)$q->fetchColumn();
 $q=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional) VALUES(:p,'Quote fixture','Sao Paulo','Teste',100,'disponivel','aprovada','disponivel') RETURNING id");$q->execute(['p'=>$owner]);$property=(int)$q->fetchColumn();
 $db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade,comissao_bps,aceita_parcelamento,entrada_bps) VALUES(:c,TRUE,1250,TRUE,3000)')->execute(['c'=>$property]);
 $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000,entrada_maxima_bps=7000 WHERE id=1');
 $start=(new DateTimeImmutable('+180 days'))->format('Y-m-d');$end=(new DateTimeImmutable('+183 days'))->format('Y-m-d');
 $reject(fn()=>$service->create($users['proprietario'],$property,$start,$end,'integral','PIX'),'Proprietario nao pode cotar seu proprio imovel');
 $reject(fn()=>$service->create($users['cliente'],$property,$start,$end,'integral','BOLETO'),'Integral rejeita boleto');
 $reject(fn()=>$service->create($users['cliente'],$property,$start,$end,'entrada_parcelamento','PIX_AUTOMATIC'),'PIX automatico sem autorizacao nativa continua indisponivel');
 $quote=$service->create($users['cliente'],$property,$start,$end,'entrada_parcelamento','BOLETO');$id=$quote['id'];$s=$quote['detalhes'];
 $check($s['valor_hospedagem_centavos']===30000&&$s['comissao_imovel_centavos']===3750&&$s['direito_proprietario_centavos']===26250,'Comissao incide somente na hospedagem');
 $check(strtotime($quote['expira_em'])-strtotime($quote['criado_em'])===900,'Checkout congela exatamente 15 minutos');
 $db->prepare('UPDATE configuracoes_financeiras SET versao=versao+1,taxa_operacao_pix_reserva_centavos=taxa_operacao_pix_reserva_centavos+100 WHERE id=:id')->execute(['id'=>$cfg['id']]);
 $db->prepare('UPDATE chacaras SET valor_diaria=999 WHERE id=:c')->execute(['c'=>$property]);
 $db->prepare('UPDATE configuracoes_comerciais_imoveis SET comissao_bps=5000,sem_mensalidade=FALSE,aceita_parcelamento=FALSE,entrada_bps=NULL,versao=versao+1 WHERE chacara_id=:c')->execute(['c'=>$property]);
 $check($service->valid($id,$users['cliente'],$property)['detalhes']==$s,'Mudancas de diaria, comissao, taxa e modalidade nao reprecificam cotacao');
 $reject(fn()=>$service->consume($id,$users['cliente'],$property,$start,$start,false),'Periodo adulterado rejeitado sem consumir cotacao');
 $db->prepare("UPDATE chacaras SET status_operacional='indisponivel' WHERE id=:c")->execute(['c'=>$property]);
 $reject(fn()=>$service->consume($id,$users['cliente'],$property,$start,$end,false),'Indisponibilidade operacional impede criacao e consumo juntos');
 $check($service->valid($id,$users['cliente'],$property)['consumida_em']===null,'Cotacao continua utilizavel apos rollback');
 $db->prepare("UPDATE chacaras SET status_operacional='disponivel' WHERE id=:c")->execute(['c'=>$property]);
 $r=$service->consume($id,$users['cliente'],$property,$start,$end,false);$rid=$r['id'];
 $row=$db->query('SELECT * FROM reservas WHERE id='.$rid)->fetch();
 $check($row['valor_total']==='300.00'&&$row['valor_liquido_proprietario_centavos']===26250&&$row['modalidade']==='entrada_parcelamento','Reserva usa valores congelados mesmo apos nova mensalidade impedir anuncio');
 $check($row['expira_em']===$quote['expira_em'],'Reserva herda o prazo sem renovar 15 minutos');
 $check($service->consume($id,$users['cliente'],$property,$start,$end,false)['id']===$rid,'Repeticao da confirmacao retorna a mesma reserva');
 $check($db->query('SELECT valor_repasse_centavos FROM repasses_reservas WHERE reserva_id='.$rid)->fetchColumn()===26250,'Repasse projeta hospedagem menos comissao');
 foreach(["UPDATE cotacoes_reserva SET detalhes='{}' WHERE id=".$db->quote($id),'UPDATE reservas SET valor_total_cliente_centavos=1 WHERE id='.$rid,'UPDATE reservas SET schema_financeiro=1 WHERE id='.$rid] as $sql){try{$db->exec($sql);throw new RuntimeException('Snapshot alterado');}catch(PDOException $e){$check($e->getCode()==='23514','Banco bloqueia alteracao do snapshot contratual');}}
}finally{
 if($db->inTransaction())$db->rollBack();
 $db->prepare('UPDATE configuracoes_financeiras SET versao=:v,taxa_operacao_pix_reserva_centavos=:o WHERE id=:id')->execute(['v'=>$cfg['versao'],'o'=>$cfg['taxa_operacao_pix_reserva_centavos'],'id'=>$cfg['id']]);
 $db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max WHERE id=1')->execute(['min'=>$limits['entrada_minima_bps'],'max'=>$limits['entrada_maxima_bps']]);
 if($property){foreach(['historico_repasses_reservas','repasses_reservas','historico_status_reservas'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE reserva_id IN (SELECT id FROM reservas WHERE chacara_id=:c)')->execute(['c'=>$property]);foreach(['reservas','cotacoes_reserva','disponibilidades','configuracoes_comerciais_imoveis'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE chacara_id=:c')->execute(['c'=>$property]);$db->prepare('DELETE FROM chacaras WHERE id=:c')->execute(['c'=>$property]);}
 if($owner)$db->prepare('DELETE FROM proprietarios WHERE id=:p')->execute(['p'=>$owner]);foreach($users as $u)$db->prepare('DELETE FROM usuarios WHERE id=:u')->execute(['u'=>$u]);
}
echo "Fase 6: cotacao e contrato verificados no PostgreSQL; fixtures removidas.\n";
