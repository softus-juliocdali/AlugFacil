<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;use App\Services\PrecificacaoReservaService;
$db=Database::getConnection();if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Banco invalido.');$ok=0;$test=function(bool$c,string$n)use(&$ok){if(!$c)throw new RuntimeException('FALHOU: '.$n);echo"OK $n\n";$ok++;};
$protected=[];foreach([2,5]as$id){$s=$db->prepare('SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=?');$s->execute([$id]);$protected[$id]=$s->fetchColumn();}
$svc=new PrecificacaoReservaService($db);$r=$svc->cotar(100000,1,'PIX',1);$test($r['valor_reserva_centavos']===100000,'valor reserva');$test($r['taxa_operacao_pix_centavos']===200,'taxa operacional');$test($r['taxa_plataforma_centavos']===0,'comissao zero');$test($r['valor_total_cliente_centavos']===100200,'total reserva mais taxa');$test($r['valor_liquido_proprietario_centavos']===100000,'proprietario integral');$test($r['quantidade_parcelas']===1,'sem parcelamento');
foreach(['CREDIT_CARD','BOLETO']as$f){try{$svc->cotar(100000,1,$f,1);$test(false,"$f recusado");}catch(RuntimeException){$test(true,"$f recusado");}}
foreach($protected as$id=>$before){$s=$db->prepare('SELECT md5(row_to_json(r)::text) FROM reservas r WHERE id=?');$s->execute([$id]);$test($s->fetchColumn()===$before,'reserva protegida #'.$id);}
$test((int)$db->query("SELECT count(*) FROM reservas WHERE origem_precificacao='legado'")->fetchColumn()>0,'historico legado preservado');$test((int)$db->query('SELECT count(*) FROM pagamentos p LEFT JOIN reservas r ON r.id=p.reserva_id WHERE r.id IS NULL')->fetchColumn()===0,'zero pagamentos orfaos');$test((int)$db->query('SELECT count(*) FROM historico_status_reservas h LEFT JOIN reservas r ON r.id=h.reserva_id WHERE r.id IS NULL')->fetchColumn()===0,'zero historicos orfaos');echo"TOTAL_OK=$ok\n";
