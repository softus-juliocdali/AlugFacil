<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Services\ReservationPricingEngine as Engine;
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo '[OK] '.$m.PHP_EOL;};
$ref=new DateTimeImmutable('2026-01-31T12:00:00-03:00');$checkin=new DateTimeImmutable('2026-07-05T14:00:00-03:00');
$s=Engine::calculate(300000,1001,1000,3000,'entrada_parcelamento',$ref,$checkin);
$check($s['quantidade_parcelas_saldo']===5&&$s['pagamentos'][0]['hospedagem_centavos']===90000,'Entrada de 30% e cinco parcelas mensais');
$check($s['pagamentos'][1]['hospedagem_centavos']===42000&&$s['pagamentos'][5]['hospedagem_centavos']===42000,'Saldo distribuido igualmente');
$check($s['comissao_imovel_centavos']===30000&&$s['direito_proprietario_centavos']===270000,'Comissao incide somente sobre hospedagem');
$check($s['pagamentos'][0]['taxa_operacional_centavos']===300&&$s['pagamentos'][0]['comissao_centavos']===9000,'Entrada recebe taxa e comissao proporcionais');
foreach(['hospedagem_centavos'=>300000,'taxa_operacional_centavos'=>1001,'comissao_centavos'=>30000,'proprietario_centavos'=>270000,'total_centavos'=>301001] as $field=>$expected)$check(array_sum(array_column($s['pagamentos'],$field))===$expected,'Fechamento exato de '.$field);
$check(Engine::monthlyDate($ref,1)->format('Y-m-d')==='2026-02-28'&&Engine::monthlyDate($ref,2)->format('Y-m-d')==='2026-03-31','Mes curto nao desloca a referencia dos meses seguintes');
$check(Engine::monthlyDate(new DateTimeImmutable('2028-01-31T12:00:00-03:00'),1)->format('Y-m-d')==='2028-02-29','Ano bissexto usa 29 de fevereiro');
$residual=Engine::calculate(300001,1001,1000,3000,'entrada_parcelamento',$ref,$checkin);$parts=array_column(array_slice($residual['pagamentos'],1),'hospedagem_centavos');$check($parts===[42000,42000,42000,42000,42001],'Centavo residual da hospedagem fica na ultima parcela');
$max=Engine::calculate(300000,1001,1000,3000,'entrada_parcelamento',$ref,new DateTimeImmutable('2030-01-01T14:00:00-03:00'));$check($max['quantidade_parcelas_saldo']===18&&count($max['pagamentos'])===19,'Maximo de dezoito parcelas alem da entrada');
$cross=Engine::calculate(100001,999,3333,3000,'entrada_parcelamento',new DateTimeImmutable('2026-01-30T23:59:00-03:00'),new DateTimeImmutable('2026-04-04T14:00:00-03:00'));
$actual=Engine::finalSchedule($cross,new DateTimeImmutable('2026-01-31T00:01:00-03:00'));
$check(count($actual)===3&&$actual[2]['vencimento_em']==='2026-03-29T00:00:00-03:00','Virada do dia preserva quantidade e antecipa ultimo vencimento para D-6');
$check(array_column($actual,'total_centavos')===array_column($cross['pagamentos'],'total_centavos'),'Calendario definitivo nao altera valores congelados');
$check($actual[1]['cancelamento_em']===$cross['limite_ultima_parcela'],'Tolerancia intermediaria nao ultrapassa D-5');
$regularSchedule=Engine::finalSchedule($s,$ref);
$check((new DateTimeImmutable($regularSchedule[1]['cancelamento_em']))->getTimestamp()-(new DateTimeImmutable($regularSchedule[2]['vencimento_em']))->getTimestamp()===432000,'Tolerancia intermediaria usa exatamente 5 vezes 24 horas quando anterior a D-5');
$check($actual[2]['cancelamento_em']===$cross['limite_ultima_parcela'],'Ultima parcela nao recebe tolerancia adicional');
foreach([[23,2,9999],[1000000000000,1000000000000,9999]] as [$h,$o,$bps]){$edge=Engine::calculate($h,$o,$bps,3000,'entrada_parcelamento',$ref,$checkin);foreach($edge['pagamentos'] as $p)if(!is_int($p['total_centavos'])||$p['proprietario_centavos']<0)throw new RuntimeException('Rateio invalido');$check(array_sum(array_column($edge['pagamentos'],'total_centavos'))===$h+$o,'Rateio preserva inteiros e direitos nao negativos em valores extremos');}
$full=Engine::calculate(300000,1001,1000,0,'integral',$ref,$checkin);$check(count($full['pagamentos'])===1&&$full['pagamentos'][0]['total_centavos']===301001,'Integral mantem pagamento unico');
echo "Fase 6: aritmetica e calendario verificados sem banco ou gateway.\n";
