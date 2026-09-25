<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';

use App\Core\Database;
use App\Models\CadastroProprietario;
use App\Models\FinanceiroProprietario;
use App\Models\Reserva;
use App\Models\User;
use App\Services\PrecificacaoReservaService;
use App\Services\CancelamentoReservaService;

if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost','::1'],true)||getenv('DB_NAME')!=='alugfacil_dev') throw new RuntimeException('Teste restrito ao banco local.');
$db=Database::getConnection();
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo '[OK] '.$message.PHP_EOL;};
$reject=static function(callable $f,string $message)use($check):void {try{$f();}catch(DomainException){$check(true,$message);return;}throw new RuntimeException($message);};
$tag=bin2hex(random_bytes(5));$uid=$guest=$pid=$cid=$rid=0;
// Generate a valid synthetic CPF, avoiding collisions with local historical data.
$cpf=(string)random_int(100000000,999999999);
for($n=9;$n<11;$n++) {$sum=0;for($i=0;$i<$n;$i++)$sum+=(int)$cpf[$i]*($n+1-$i);$cpf.=(string)((10*$sum%11)%10);}
try {
    $stmt=$db->prepare('SELECT CAST(:no AS boolean) no,CAST(:yes AS boolean) yes,CAST(:n AS integer) n,CAST(:nil AS text) nil');
    $stmt->execute(['no'=>false,'yes'=>true,'n'=>42,'nil'=>null]);$v=$stmt->fetch();
    $check($v['no']===false&&$v['yes']===true&&$v['n']===42&&$v['nil']===null,'Bindings booleanos, inteiro e nulo no PostgreSQL real');
    $stmt=$db->prepare('SELECT CAST(? AS boolean) flag');$stmt->execute([false]);$check($stmt->fetchColumn()===false,'Binding posicional false');
    $stmt=$db->prepare('SELECT CAST(:flag AS boolean)');$stmt->bindValue(':flag',false,PDO::PARAM_BOOL);$stmt->execute();$check($stmt->fetchColumn()===false,'bindValue + execute sem parametros preservado');
    $user=new User();$uid=$user->createOwner(['nome'=>'Fixture Phase 1','telefone'=>'11999998888','email'=>'phase1-'.$tag.'@example.test','senha'=>'teste-local-apenas']);
    $pid=(int)$user->findOwnerByUserId($uid)['id'];
    $data=['tipo_pessoa'=>'PF','cpf_cnpj'=>$cpf,'nome_razao_social'=>'Fixture Phase 1','nome_fantasia'=>'',
        'data_nascimento'=>'1990-01-01','tipo_empresa'=>'','renda_faturamento_mensal'=>'1.234,56',
        'telefone'=>'1133334444','celular'=>'11999998888','email_financeiro'=>'phase1-'.$tag.'@example.test',
        'cep'=>'01001000','endereco'=>'Rua Teste','numero'=>'1','complemento'=>'','bairro'=>'Centro','cidade'=>'Sao Paulo','estado'=>'SP','versao_cadastro'=>0];
    $user->updateOwnerData($uid,$data);$model=new CadastroProprietario();$row=$model->buscar($pid);
    $check($row['cpf_cnpj']===$cpf&&$row['dados_completos']===true,'User::updateOwnerData salva CPF canonico sem HY093');
    $check((int)$row['renda_faturamento_mensal_centavos']===123456,'Renda preserva centavos');
    $check((new FinanceiroProprietario())->buscar($pid)['cpf_cnpj']===$cpf,'Recebimentos le a fonte canonica');
    $check($user->buscarProprietarioAdministrativo($pid)['cpf_cnpj']===$cpf,'Admin le a fonte canonica');
    $check($user->findOwnerByUserId($uid)['cpf']===null,'CPF legado nao virou fonte nem foi sobrescrito');
    $reject(fn()=>$user->updateOwnerData($uid,$data),'Atualizacao com versao antiga recusada');
    $data['versao_cadastro']=1;$data['senha']='nova-senha-teste';$user->updateOwnerData($uid,$data);
    $check(password_verify('nova-senha-teste',$user->findById($uid)['senha_hash']),'Senha e cadastro atualizados na mesma transacao');
    $data['versao_cadastro']=2;$data['tipo_pessoa']='PJ';$data['cpf_cnpj']='11222333000181';$data['nome_razao_social']='Fixture PJ '.$tag;$data['tipo_empresa']='LIMITED';$data['data_nascimento']='';
    $user->updateOwnerData($uid,$data);$row=$model->buscar($pid);
    $check(strlen($row['cpf_cnpj'])===14&&$row['tipo_pessoa']==='PJ'&&$row['data_nascimento']===null,'CNPJ de 14 digitos, razao social e tipo PJ persistidos');
    $data['versao_cadastro']=3;$data['cpf_cnpj']='00000000000000';
    $reject(fn()=>$user->updateOwnerData($uid,$data),'CNPJ invalido recusado sem alteracao parcial');
    $check($model->buscar($pid)['cpf_cnpj']==='11222333000181','Validacao preserva documento anterior');
    $db->prepare("INSERT INTO proprietario_cadastro_conflitos(proprietario_id,campo,fontes) VALUES(:p,'cpf_cnpj','{}')")->execute(['p'=>$pid]);
    $data['cpf_cnpj']='11222333000181';unset($data['senha']);
    $reject(fn()=>$user->updateOwnerData($uid,$data),'Conflito nao e resolvido silenciosamente');
    $data['confirmar_correcao']='1';$data['motivo_correcao']='Correcao explicita em teste local';$user->updateOwnerData($uid,$data);
    $check($model->conflitos($pid)===[],'Resolucao explicita e auditada');
    $guest=$user->createClient(['nome'=>'Hospede Fixture','telefone'=>'11999998888','email'=>'guest-'.$tag.'@example.test','senha'=>'teste-local-apenas']);
    $s=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional) VALUES(:p,:n,'Sao Paulo','Rua Teste',100,'disponivel','aprovada','disponivel') RETURNING id");$s->execute(['p'=>$pid,'n'=>'Fixture '.$tag]);$cid=(int)$s->fetchColumn();
    if($db->query("SELECT to_regclass('public.configuracoes_comerciais_imoveis')")->fetchColumn())$db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade,comissao_bps) VALUES(:c,TRUE,0)')->execute(['c'=>$cid]);
    $snap=(new PrecificacaoReservaService())->cotar(10000,2);
    $start=(new DateTimeImmutable('+90 days'))->format('Y-m-d');$end=(new DateTimeImmutable('+92 days'))->format('Y-m-d');$checkin=new DateTimeImmutable($start.' 14:00:00-03:00');$cutoff=$checkin->modify('-24 hours')->format(DATE_ATOM);
    $reservation=$snap+['usuario_id'=>$guest,'proprietario_id'=>$pid,'chacara_id'=>$cid,'data_inicio'=>$start,'data_fim'=>$end,'valor_diaria'=>'100.00','valor_total'=>PrecificacaoReservaService::centavosParaDecimal($snap['valor_hospedagem_centavos']),'precificacao_detalhes'=>$snap,'checkin_hora_inicial_snapshot'=>'14:00','checkin_hora_final_snapshot'=>'22:00','checkout_hora_inicial_snapshot'=>'08:00','checkout_hora_final_snapshot'=>'12:00','checkin_inicio_em'=>$checkin->format(DATE_ATOM),'cancelamento_permitido_ate'=>$cutoff,'repasse_liberavel_em'=>$cutoff,'aceite_reserva_menos_24h'=>false];
    $rid=(int)(new Reserva())->criar($reservation)['id'];
    $check($db->query('SELECT aceite_reserva_menos_24h FROM reservas WHERE id='.$rid)->fetchColumn()===false,'Reserva real com aceite false criada sem 22P02');
    $db->prepare("UPDATE reservas SET status_reserva='confirmada',status_pagamento='pago' WHERE id=:r")->execute(['r'=>$rid]);
    $db->prepare("INSERT INTO pagamentos(reserva_id,usuario_id,valor,forma_pagamento,status_pagamento,data_pagamento) VALUES(:r,:u,:v,'pix','pago',CURRENT_TIMESTAMP)")->execute(['r'=>$rid,'u'=>$guest,'v'=>PrecificacaoReservaService::centavosParaDecimal($snap['valor_total_cliente_centavos'])]);
    $refund=(new CancelamentoReservaService())->cancelarUsuario($rid,$guest,null,'Teste de schema');
    $check($refund['reembolso_id']>0&&$db->query('SELECT status_pagamento FROM reservas WHERE id='.$rid)->fetchColumn()==='reembolso_processando','Cancelamento pago persiste estado de 21 caracteres e intencao de refund');
    $check($db->query('SELECT status_repasse FROM reservas WHERE id='.$rid)->fetchColumn()==='cancelado','Projecao de repasse acompanha estado canonico');
    $db->beginTransaction();try {$db->prepare("UPDATE reservas SET status_pagamento='estado_inventado' WHERE id=:r")->execute(['r'=>$rid]);throw new RuntimeException('CHECK ausente');}catch(PDOException $e){$check($e->getCode()==='23514','Dominio rejeita estado desconhecido');}finally{$db->rollBack();}
} finally {
    if($db->inTransaction())$db->rollBack();
    if($rid) {
        foreach(['tarefas_financeiras_reserva','historico_reembolsos_reservas','reembolsos_reservas','historico_repasses_reservas','repasses_reservas','historico_status_reservas','pagamentos'] as $table) {
            if($db->query("SELECT to_regclass('public.$table')")->fetchColumn()) $db->prepare('DELETE FROM '.$table.' WHERE reserva_id=:r')->execute(['r'=>$rid]);
        }
        $db->prepare('DELETE FROM reservas WHERE id=:r')->execute(['r'=>$rid]);
    }
    if($cid){if($db->query("SELECT to_regclass('public.configuracoes_comerciais_imoveis')")->fetchColumn())$db->prepare('DELETE FROM configuracoes_comerciais_imoveis WHERE chacara_id=:c')->execute(['c'=>$cid]);$db->prepare('DELETE FROM chacaras WHERE id=:c')->execute(['c'=>$cid]);}
    if($pid) {
        if($db->query("SELECT to_regclass('public.onboarding_fila')")->fetchColumn())$db->prepare('DELETE FROM onboarding_fila WHERE proprietario_id=:p')->execute(['p'=>$pid]);
        foreach(['proprietario_cadastro_conflitos','auditoria_dados_financeiros','proprietario_cadastro'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE proprietario_id=:p')->execute(['p'=>$pid]);
        $db->prepare('DELETE FROM proprietarios WHERE id=:p')->execute(['p'=>$pid]);
    }
    foreach([$uid,$guest] as $id)if($id)$db->prepare('DELETE FROM usuarios WHERE id=:u')->execute(['u'=>$id]);
}
echo "Fase 1: testes PostgreSQL concluidos, fixtures removidas; nenhuma chamada Asaas.\n";
