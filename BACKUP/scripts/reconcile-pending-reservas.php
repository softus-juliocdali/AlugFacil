<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
$apply=in_array('--apply',$argv,true);$db=App\Core\Database::getConnection();
if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Comando permitido somente em alugfacil_dev.');
$sql="SELECT r.id,r.data_reserva,r.data_inicio,r.data_fim,r.status_pagamento,r.id_cobranca_asaas, EXISTS(SELECT 1 FROM pagamentos p WHERE p.reserva_id=r.id AND p.status_pagamento='pago') AS pagamento_confirmado, EXISTS(SELECT 1 FROM pagamentos p WHERE p.reserva_id=r.id AND p.status_pagamento='pendente') AS pagamento_pendente FROM reservas r WHERE r.status_reserva='aguardando_pagamento' AND r.expira_em IS NULL ORDER BY r.data_reserva,r.id";
$reservas=$db->query($sql)->fetchAll();echo 'modo='.($apply?'apply':'dry-run').PHP_EOL.'candidatas='.count($reservas).PHP_EOL;$alteradas=0;
foreach($reservas as $r){$classe=($r['pagamento_confirmado']||$r['status_pagamento']==='pago')?'C_pagamento_confirmado':($r['pagamento_pendente']?'B_pagamento_pendente':(empty($r['id_cobranca_asaas'])?'D_sem_cobranca':'A_antiga_sem_pagamento'));echo sprintf("reserva=%d classe=%s criada=%s periodo=%s..%s cobranca=%s bloqueia=sim\n",$r['id'],$classe,$r['data_reserva'],$r['data_inicio'],$r['data_fim'],empty($r['id_cobranca_asaas'])?'nao':'sim');if($apply&&!$r['pagamento_confirmado']&&$r['status_pagamento']!=='pago'){(new App\Services\ReservaStatusService($db))->transicionar((int)$r['id'],'expirada',['motivo'=>'Conciliacao administrativa de reserva historica sem prazo','origem'=>'sistema','responsavel_tipo'=>'sistema']);$alteradas++;}}
echo 'alteradas='.$alteradas.PHP_EOL;
