<?php

declare(strict_types=1);

require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\AsaasWebhookEvento;
use App\Services\AffiliateCommissionService;
use App\Services\MensalidadeAnuncioService;

$db=Database::getConnection();if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Teste permitido somente em alugfacil_dev.');
$ok=0;$fail=0;$check=static function(string$name,bool$condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$tag=(string)random_int(10000000,99999999);$db->beginTransaction();
try{
    $userInsert=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES(:nome,:email,'hash',:tipo,'ativo') RETURNING id");
    $userInsert->execute(['nome'=>'Admin AF4','email'=>'admin.af4.'.$tag.'@test.local','tipo'=>'admin']);$adminId=(int)$userInsert->fetchColumn();
    $userInsert->execute(['nome'=>'Owner AF4','email'=>'owner.af4.'.$tag.'@test.local','tipo'=>'proprietario']);$ownerUserId=(int)$userInsert->fetchColumn();
    $userInsert->execute(['nome'=>'Common AF4','email'=>'common.af4.'.$tag.'@test.local','tipo'=>'proprietario']);$commonUserId=(int)$userInsert->fetchColumn();
    $affiliateInsert=$db->prepare("INSERT INTO afiliados(nome,cpf_cnpj,telefone,email,senha_hash,chave_pix,tipo_chave_pix,status) VALUES('Afiliado AF4',:doc,'11999999999',:email,'hash',:pix,'aleatoria','ativo') RETURNING id");
    $affiliateInsert->execute(['doc'=>'39053344705','email'=>'affiliate.af4.'.$tag.'@test.local','pix'=>'pix-'.$tag]);$affiliateId=(int)$affiliateInsert->fetchColumn();
    $ownerInsert=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status,afiliado_id,afiliado_origem,afiliado_atribuido_em) VALUES(:usuario,:nome,:email,'ativo',CAST(:afiliado AS BIGINT),:origem,CASE WHEN CAST(:afiliado AS BIGINT) IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END) RETURNING id");
    $ownerInsert->execute(['usuario'=>$ownerUserId,'nome'=>'Owner AF4','email'=>'owner.af4.'.$tag.'@test.local','afiliado'=>$affiliateId,'origem'=>'codigo']);$ownerId=(int)$ownerInsert->fetchColumn();
    $ownerInsert->execute(['usuario'=>$commonUserId,'nome'=>'Common AF4','email'=>'common.af4.'.$tag.'@test.local','afiliado'=>null,'origem'=>null]);$commonOwnerId=(int)$ownerInsert->fetchColumn();
    $propertyInsert=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,valor_diaria,cidade,endereco,status,status_aprovacao,status_operacional) VALUES(:owner,:nome,100,'Sao Paulo','Rua Teste','disponivel','aprovada','disponivel') RETURNING id");
    $propertyInsert->execute(['owner'=>$ownerId,'nome'=>'Chacara A '.$tag]);$propertyA=(int)$propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner'=>$ownerId,'nome'=>'Chacara B '.$tag]);$propertyB=(int)$propertyInsert->fetchColumn();
    $propertyInsert->execute(['owner'=>$commonOwnerId,'nome'=>'Chacara Comum '.$tag]);$propertyCommon=(int)$propertyInsert->fetchColumn();
    $monthlyInsert=$db->prepare("INSERT INTO mensalidades_anuncios(chacara_id,proprietario_id,ativa,valor_centavos,status,asaas_subscription_id) VALUES(:chacara,:owner,TRUE,:valor,'PENDENTE',:subscription) RETURNING id");
    $monthlyInsert->execute(['chacara'=>$propertyA,'owner'=>$ownerId,'valor'=>10000,'subscription'=>'sub_af4_a_'.$tag]);$monthlyA=(int)$monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara'=>$propertyB,'owner'=>$ownerId,'valor'=>20000,'subscription'=>'sub_af4_b_'.$tag]);$monthlyB=(int)$monthlyInsert->fetchColumn();
    $monthlyInsert->execute(['chacara'=>$propertyCommon,'owner'=>$commonOwnerId,'valor'=>10000,'subscription'=>'sub_af4_c_'.$tag]);$monthlyCommon=(int)$monthlyInsert->fetchColumn();
    $db->prepare('UPDATE afiliados SET percentual_comissao_bps=1000 WHERE id=:id')->execute(['id'=>$affiliateId]);

    $service=new MensalidadeAnuncioService($db);$finance=new AffiliateCommissionService($db);
    $now=new DateTimeImmutable('2026-09-01T12:00:00-03:00');
    foreach(['individual','legado'] as $mode){
        $db->exec('SAVEPOINT mode_fixture');
        $pid='fixture-monthly-refund-'.$mode.'-'.$tag;
        $payment=['id'=>$pid,'value'=>100,'status'=>'RECEIVED','confirmedDate'=>'2026-08-01T12:00:00-03:00','dueDate'=>'2026-08-01','subscription'=>'sub_af4_a_'.$tag];
        if($mode==='individual'){
            $q=$db->prepare("INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,vencimento,valor_centavos,versao_global,versao_imovel,afiliado_id,percentual_afiliado_bps,comissao_afiliado_centavos,versao_afiliado,asaas_payment_id) VALUES(:c,:p,'2026-08-01',10000,1,1,:a,1000,1000,1,:payment) RETURNING id");
            $q->execute(['c'=>$propertyA,'p'=>$ownerId,'a'=>$affiliateId,'payment'=>$pid]);$obligation=(int)$q->fetchColumn();
            $db->prepare("INSERT INTO cobrancas_mensalidades(mensalidade_id,obrigacao_id,asaas_payment_id,valor_centavos,status,vencimento) VALUES(:m,:o,:p,10000,'PENDENTE','2026-08-01')")->execute(['m'=>$monthlyA,'o'=>$obligation,'p'=>$pid]);
        }
        $sequence=0;
        $send=function(string $type,array $changes=[])use($service,$payment,$pid,$tag,&$sequence):array{
            return $service->processarPagamento(['asaas_event_id'=>'fixture-refund-event-'.$tag.'-'.(++$sequence),'asaas_payment_id'=>$pid],array_replace($payment,$changes),$type);
        };
        $refund=static fn(string $status,int $value=100,string $id='attempt-1'):array=>['refunds'=>[['id'=>$id,'status'=>$status,'value'=>$value]]];
        $row=function()use($db,$pid):array{$q=$db->prepare('SELECT cm.*,o.estado AS obrigacao_estado,c.status AS comissao_status,c.estornada_em,c.id AS comissao_id FROM cobrancas_mensalidades cm LEFT JOIN obrigacoes_mensalidades o ON o.id=cm.obrigacao_id LEFT JOIN comissoes_afiliados c ON c.cobranca_mensalidade_id=cm.id WHERE cm.asaas_payment_id=:p');$q->execute(['p'=>$pid]);return $q->fetch();};
        $balance=fn()=>$finance->summary($affiliateId,$now);
        $adjustments=fn()=>(int)$db->query("SELECT COUNT(*) FROM ajustes_afiliados WHERE afiliado_id=$affiliateId")->fetchColumn();
        $validPaid=fn(array $r)=>$r['status']==='PAGA'&&($mode==='legado'||$r['obrigacao_estado']==='paga')&&$r['estornada_em']===null;
        $send('PAYMENT_RECEIVED');$db->exec('SAVEPOINT paid_fixture');

        $check("$mode: comissao inicialmente disponivel",$balance()['saldo_disponivel_centavos']===1000);
        $send('PAYMENT_REFUND_REQUESTED');
        $check("$mode: solicitado bloqueia sem estornar",$row()['refund_estado']==='solicitado'&&$validPaid($row())&&$balance()['bloqueado_centavos']===1000);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));
        $check("$mode: PENDING duplicado conserva obrigacao e ledger",$row()['refund_estado']==='processando'&&$validPaid($row())&&$balance()['saldo_disponivel_centavos']===0&&$adjustments()===0);
        $rejected=false;try{$finance->registerManualPayment($affiliateId,1,'2026-09-01',null,null,$adminId,$now);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;$rejected=true;}
        $check("$mode: pagamento manual revalida bloqueio",$rejected&&$balance()['total_pago_centavos']===0);
        $portal=(new App\Services\AffiliatePortalService($db))->commissions($affiliateId,['status'=>'BLOQUEADA'],1,30,$now);
        $check("$mode: portal e administracao exibem bloqueio",$portal['total']===1&&$finance->commissions($affiliateId)[0]['refund_bloqueado']);
        $send('PAYMENT_UPDATED',$refund('DONE'));$send('PAYMENT_UPDATED',$refund('DONE'));
        $r=$row();$check("$mode: PENDING para DONE efetiva um estorno",$r['refund_estado']==='concluido'&&!$r['refund_bloqueado']&&$r['status']==='ESTORNADA'&&$r['comissao_status']==='ESTORNADA'&&($mode==='legado'||$r['obrigacao_estado']==='estornada')&&$adjustments()===0);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_RECEIVED');
        $check("$mode: eventos atrasados nao reabrem refund concluido",$row()['refund_estado']==='concluido'&&$row()['comissao_status']==='ESTORNADA'&&$balance()['saldo_disponivel_centavos']===0);
        $check("$mode: duplicidade nao cria comissao",(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE afiliado_id=$affiliateId")->fetchColumn()===1);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_UPDATED',$refund('CANCELLED'));$send('PAYMENT_UPDATED',$refund('CANCELLED'));
        $check("$mode: PENDING para falhou restaura disponibilidade",$row()['refund_estado']==='falhou'&&!$row()['refund_bloqueado']&&$validPaid($row())&&$balance()['saldo_disponivel_centavos']===1000&&$adjustments()===0);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_UPDATED',$refund('UNKNOWN'));$send('PAYMENT_RECEIVED');
        $check("$mode: falha terminal nao regride por evento antigo",$row()['refund_estado']==='falhou'&&$balance()['saldo_disponivel_centavos']===1000);
        $finance->registerManualPayment($affiliateId,1000,'2026-09-01','fixture',null,$adminId,$now);
        $check("$mode: liberacao permite apenas o saldo original",$balance()['total_pago_centavos']===1000&&$balance()['saldo_disponivel_centavos']===0);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));
        $outcome=$send('PAYMENT_UPDATED',$refund('UNKNOWN'));$send('PAYMENT_UPDATED',$refund('UNKNOWN'));$send('PAYMENT_RECEIVED');
        $check("$mode: resultado desconhecido exige conciliacao e mantem bloqueio",$outcome['status']==='divergente'&&$row()['refund_estado']==='conciliacao_manual'&&$validPaid($row())&&$balance()['saldo_disponivel_centavos']===0&&$adjustments()===0);
        $send('PAYMENT_UPDATED',$refund('DONE'));
        $check("$mode: desconhecido depois comprovado concluido estorna",$row()['refund_estado']==='concluido'&&$row()['estornada_em']!==null);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $finance->registerManualPayment($affiliateId,400,'2026-09-01','fixture parcial',null,$adminId,$now);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));
        $check("$mode: comissao parcialmente paga so bloqueia saldo remanescente",$balance()['bloqueado_centavos']===600&&$adjustments()===0&&$row()['estornada_em']===null);
        $send('PAYMENT_UPDATED',$refund('FAILED'));
        $check("$mode: falha restaura apenas remanescente",$balance()['saldo_disponivel_centavos']===600&&$balance()['total_pago_centavos']===400);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $finance->registerManualPayment($affiliateId,1000,'2026-09-01','fixture paga',null,$adminId,$now);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));
        $check("$mode: refund pendente nao debita comissao ja paga",$adjustments()===0&&$row()['estornada_em']===null);
        $send('PAYMENT_UPDATED',$refund('DONE'));$send('PAYMENT_REFUNDED',['status'=>'REFUNDED']);$send('PAYMENT_UPDATED',$refund('DONE'));
        $check("$mode: conclusao e repeticoes geram um ajuste exato",$adjustments()===1&&$balance()['ajustes_centavos']===-1000&&$row()['comissao_status']==='PAGA');
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_UPDATED',$refund('REFUSED'));
        $early=$finance->summary($affiliateId,new DateTimeImmutable('2026-08-02T12:00:00-03:00'));
        $check("$mode: falha nao antecipa carencia de sete dias",$early['saldo_disponivel_centavos']===0&&$early['em_aberto_centavos']===1000);
        $conflict=$send('PAYMENT_UPDATED',$refund('DONE'));
        $send('PAYMENT_DELETED');
        $check("$mode: terminais contraditorios preservam evidencia e bloqueiam",$conflict['status']==='divergente'&&$row()['refund_estado']==='falhou'&&$row()['refund_bloqueado']&&$row()['estornada_em']===null);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_UPDATED',['status'=>'REFUND_REQUESTED']);
        $check("$mode: reconciliacao solicitada sem evento dedicado bloqueia",$row()['refund_estado']==='solicitado'&&$balance()['saldo_disponivel_centavos']===0);
        $send('PAYMENT_REFUND_DENIED');
        $check("$mode: recusa explicita encerra bloqueio",$row()['refund_estado']==='falhou'&&$balance()['saldo_disponivel_centavos']===1000);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_PARTIALLY_REFUNDED',$refund('DONE',40));
        $check("$mode: parcial inconclusivo para obrigacao integral nao gera estorno total",$row()['refund_estado']==='conciliacao_manual'&&$validPaid($row())&&$balance()['saldo_disponivel_centavos']===0);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');

        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));$send('PAYMENT_DELETED');
        $check("$mode: cancelamento de instrumento nao conclui refund pendente",$validPaid($row())&&$row()['refund_bloqueado']);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');
        $send('PAYMENT_UPDATED',['refunds'=>[['id'=>'attempt-1','status'=>'DONE','value'=>'invalid']]]);
        $check("$mode: valor de conclusao invalido permanece em conciliacao",$row()['refund_estado']==='conciliacao_manual'&&$row()['refund_bloqueado']&&$validPaid($row()));
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');
        $send('PAYMENT_UPDATED',$refund('PENDING',100,'attempt-1'));
        $send('PAYMENT_UPDATED',$refund('PENDING',100,'attempt-2'));
        $send('PAYMENT_UPDATED',$refund('FAILED',100,'attempt-1'));
        $check("$mode: falha de tentativa diferente nao libera outra pendente",$row()['refund_estado']==='conciliacao_manual'&&$balance()['saldo_disponivel_centavos']===0);
        $send('PAYMENT_UPDATED',['status'=>'REFUNDED']);
        $check("$mode: prova de refund integral resolve tentativas ambiguas",$row()['refund_estado']==='concluido'&&$row()['estornada_em']!==null);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');
        $send('PAYMENT_UPDATED',['refunds'=>[['status'=>'PENDING','value'=>40],['status'=>'PENDING','value'=>60]]]);
        $send('PAYMENT_REFUND_DENIED');
        $check("$mode: recusa sem correlacao nao libera multiplos refunds",$row()['refund_estado']==='conciliacao_manual'&&$balance()['saldo_disponivel_centavos']===0);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');
        $send('PAYMENT_REFUND_DENIED');
        $send('PAYMENT_UPDATED',$refund('PENDING',100,'new-attempt'));
        $check("$mode: nova tentativa apos falha sem identidade exige revisao",$row()['refund_estado']==='falhou'&&$row()['refund_bloqueado']);
        $db->exec('ROLLBACK TO SAVEPOINT paid_fixture');
        $db->prepare('UPDATE mensalidades_anuncios SET ativa=FALSE,valor_centavos=NULL WHERE id=:id')->execute(['id'=>$monthlyA]);
        $send('PAYMENT_REFUND_IN_PROGRESS',$refund('PENDING'));
        $check("$mode: desativacao comercial nao ignora refund existente",$row()['refund_bloqueado']&&$validPaid($row()));
        $db->exec('ROLLBACK TO SAVEPOINT mode_fixture');
    }
}finally{if($db->inTransaction())$db->rollBack();}
echo "Total: ".($ok+$fail)." | Aprovados: $ok | Falhos: $fail\n";exit($fail?1:0);
