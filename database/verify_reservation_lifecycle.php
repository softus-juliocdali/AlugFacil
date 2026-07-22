<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/scripts/bootstrap.php';
$db=App\Core\Database::getConnection();$falhas=0;
$check=function(string $nome,bool $ok)use(&$falhas){echo($ok?'[OK] ':'[FALHA] ').$nome.PHP_EOL;if(!$ok)$falhas++;};
$check('database_alugfacil_dev',$db->query('SELECT current_database()')->fetchColumn()==='alugfacil_dev');
$check('tabela_historico',(bool)$db->query("SELECT to_regclass('public.historico_status_reservas') IS NOT NULL")->fetchColumn());
$check('campos_reserva',(int)$db->query("SELECT count(*) FROM information_schema.columns WHERE table_name='reservas' AND column_name IN ('expira_em','cancelada_em','cancelamento_solicitado_em','inicio_real_em','finalizada_em','ultima_transicao_em','versao_status','divergencia_pagamento')")->fetchColumn()===8);
$check('nenhum_dado_smoke',(int)$db->query("SELECT count(*) FROM usuarios WHERE email LIKE 'teste.reserva.%@localhost.test'")->fetchColumn()===0);
$check('nenhum_historico_orfao',(int)$db->query('SELECT count(*) FROM historico_status_reservas h LEFT JOIN reservas r ON r.id=h.reserva_id WHERE r.id IS NULL')->fetchColumn()===0);
echo 'tabelas='.$db->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")->fetchColumn().PHP_EOL;
echo 'status_reservas='.json_encode($db->query('SELECT status_reserva,count(*) quantidade FROM reservas GROUP BY 1 ORDER BY 1')->fetchAll()).PHP_EOL;
echo 'status_pagamentos_reserva='.json_encode($db->query('SELECT status_pagamento,count(*) quantidade FROM reservas GROUP BY 1 ORDER BY 1')->fetchAll()).PHP_EOL;
echo 'status_pagamentos='.json_encode($db->query('SELECT status_pagamento,count(*) quantidade FROM pagamentos GROUP BY 1 ORDER BY 1')->fetchAll()).PHP_EOL;
echo 'pagamentos_sem_reserva='.$db->query('SELECT count(*) FROM pagamentos p LEFT JOIN reservas r ON r.id=p.reserva_id WHERE r.id IS NULL')->fetchColumn().PHP_EOL;
echo 'pendentes_sem_cobranca='.$db->query("SELECT count(*) FROM reservas WHERE status_reserva='aguardando_pagamento' AND id_cobranca_asaas IS NULL")->fetchColumn().PHP_EOL;
$db->beginTransaction();
try{
 $uid=(int)$db->query("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Teste reserva','reserva-lifecycle@invalid.local','x','cliente','ativo') RETURNING id")->fetchColumn();
 $ouid=(int)$db->query("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Teste proprietario','owner-lifecycle@invalid.local','x','proprietario','ativo') RETURNING id")->fetchColumn();
 $s=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Teste proprietario','owner-lifecycle@invalid.local','ativo') RETURNING id");$s->execute(['u'=>$ouid]);$pid=(int)$s->fetchColumn();
 $s=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,valor_diaria,cidade,endereco,status,status_aprovacao,status_operacional) VALUES(:p,'Imovel teste',100,'Teste','Teste','disponivel','aprovada','disponivel') RETURNING id");$s->execute(['p'=>$pid]);$cid=(int)$s->fetchColumn();
 $s=$db->prepare("INSERT INTO reservas(usuario_id,proprietario_id,chacara_id,data_inicio,data_fim,quantidade_diarias,valor_diaria,valor_total,status_reserva,status_pagamento,expira_em) VALUES(:u,:p,:c,CURRENT_DATE+20,CURRENT_DATE+22,2,100,200,'aguardando_pagamento','pendente',CURRENT_TIMESTAMP+INTERVAL '30 minutes') RETURNING id");$s->execute(['u'=>$uid,'p'=>$pid,'c'=>$cid]);$rid=(int)$s->fetchColumn();$svc=new App\Services\ReservaStatusService($db);$svc->registrarInicial($rid);$m=new App\Models\Reserva();
 $row=$db->query("SELECT * FROM reservas WHERE id={$rid}")->fetch();$check('nova_reserva_tem_expiracao',!empty($row['expira_em']));$check('historico_inicial',(int)$db->query("SELECT count(*) FROM historico_status_reservas WHERE reserva_id={$rid}")->fetchColumn()===1);
 $db->exec("UPDATE reservas SET expira_em=CURRENT_TIMESTAMP-INTERVAL '1 minute' WHERE id={$rid}");$check('expiracao_processada',$svc->expirarVencidas(10,$cid)===1);$check('expiracao_idempotente',$svc->expirarVencidas(10,$cid)===0);$check('expirada_libera_datas',!$m->existeConflitoReserva($cid,date('Y-m-d',strtotime('+20 days')),date('Y-m-d',strtotime('+22 days'))));
 $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$check('testes_transacionais',false);echo 'detalhe='.$e->getMessage().PHP_EOL;}
exit($falhas===0?0:1);
