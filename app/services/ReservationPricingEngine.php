<?php
declare(strict_types=1);
namespace App\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Pure integer arithmetic. Gateway fees do not reduce the contracted owner's entitlement. */
final class ReservationPricingEngine
{
 public const CALENDAR_D6='referencia_pagamento_entrada_ultimo_ajuste_D6_v2';
 public static function cutoff(DateTimeImmutable $checkin):DateTimeImmutable{return $checkin->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0,0)->modify('-5 days');}
 public static function monthlyDate(DateTimeImmutable $reference,int $offset):DateTimeImmutable
 {$reference=$reference->setTimezone(new DateTimeZone('America/Sao_Paulo'));$month=$reference->modify('first day of this month')->setTime(0,0)->modify('+'.$offset.' months');return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$reference->format('d'),(int)$month->format('t')));}
 public static function calculate(int $hospitality,int $operationFee,int $commissionBps,int $depositBps,string $mode,DateTimeImmutable $reference,DateTimeImmutable $checkin):array
 {
  if($hospitality<=0||$hospitality>1000000000000||$operationFee<0||$operationFee>1000000000000||$commissionBps<0||$commissionBps>10000)throw new RuntimeException('Valores financeiros fora do dominio.');
  if(!in_array($mode,['integral','entrada_parcelamento'],true))throw new RuntimeException('Modalidade invalida.');
  $commission=intdiv($hospitality*$commissionBps+5000,10000);$dates=[];$parts=[];$cutoff=self::cutoff($checkin);
  if($mode==='integral'){$parts=[$hospitality];$dates=[$reference];$depositBps=10000;}
  else{
   if($depositBps<=0||$depositBps>=10000)throw new RuntimeException('Entrada deve deixar saldo positivo.');
   for($i=1;$i<=18;$i++){$due=self::monthlyDate($reference,$i);if($due>$cutoff)break;$dates[]=$due;}
   $count=count($dates);if($count===0)throw new RuntimeException('Nao ha parcela mensal possivel ate D-5. Escolha pagamento integral.');
   $deposit=intdiv($hospitality*$depositBps+5000,10000);$balance=$hospitality-$deposit;
   if($deposit<=0||$balance<$count)throw new RuntimeException('Valor insuficiente para entrada e parcelas positivas.');
   $regular=intdiv($balance,$count);$parts=[$deposit];for($i=1;$i<=$count;$i++)$parts[]=$i===$count?$balance-$regular*($count-1):$regular;array_unshift($dates,$reference);
  }
  $payments=[];$feeUsed=$commissionUsed=$hospitalityUsed=0;$last=count($parts)-1;
  if($mode==='entrada_parcelamento'&&$dates[$last]==$cutoff)$dates[$last]=$cutoff->modify('-1 day');
  foreach($parts as $i=>$part){$hospitalityUsed+=$part;$fee=$i===$last?$operationFee-$feeUsed:self::proportion($operationFee,$hospitalityUsed,$hospitality)-$feeUsed;$share=$i===$last?$commission-$commissionUsed:self::proportion($commission,$hospitalityUsed,$hospitality)-$commissionUsed;$feeUsed+=$fee;$commissionUsed+=$share;
   $payments[]=['numero'=>$i,'tipo'=>$mode==='integral'?'integral':($i===0?'entrada':'parcela'),'hospedagem_centavos'=>$part,'taxa_operacional_centavos'=>$fee,'comissao_centavos'=>$share,'proprietario_centavos'=>$part-$share,'total_centavos'=>$part+$fee,'vencimento_provisorio'=>$dates[$i]->format(DATE_ATOM)];}
  return ['modalidade'=>$mode,'valor_hospedagem_centavos'=>$hospitality,'taxa_operacional_centavos'=>$operationFee,'comissao_imovel_bps'=>$commissionBps,'comissao_imovel_centavos'=>$commission,'direito_proprietario_centavos'=>$hospitality-$commission,'entrada_bps'=>$depositBps,'quantidade_parcelas_saldo'=>$mode==='integral'?0:$last,'quantidade_pagamentos'=>count($payments),'total_centavos'=>$hospitality+$operationFee,'pagamentos'=>$payments,'limite_ultima_parcela'=>$cutoff->format(DATE_ATOM),'regra'=>'financeiro_v2','calendario_regra'=>self::CALENDAR_D6];
 }
 public static function finalSchedule(array $snapshot,DateTimeImmutable $depositPaid):array
 {
  if($snapshot['modalidade']!=='entrada_parcelamento')throw new RuntimeException('Cronograma mensal exige entrada.');
  $cutoff=new DateTimeImmutable($snapshot['limite_ultima_parcela']);$count=(int)$snapshot['quantidade_parcelas_saldo'];$out=$snapshot['pagamentos'];
  $out[0]['vencimento_em']=$depositPaid->format(DATE_ATOM);$dates=[];
  for($i=1;$i<=$count;$i++){$due=self::monthlyDate($depositPaid,$i);if($i===$count&&$due>$cutoff)$due=$cutoff;if($i===$count&&$due==$cutoff&&($snapshot['calendario_regra']??'')===self::CALENDAR_D6)$due=$cutoff->setTimezone(new DateTimeZone('America/Sao_Paulo'))->modify('-1 day');if($due>$cutoff||$due<=$depositPaid||($i>1&&$due<=$dates[$i-1]))throw new RuntimeException('Pagamento da entrada incompativel com cronograma congelado.');$dates[$i]=$due;$out[$i]['vencimento_em']=$due->format(DATE_ATOM);}
  for($i=1;$i<=$count;$i++){$deadline=$i===$count?$cutoff:min($cutoff,$dates[$i+1]->setTimestamp($dates[$i+1]->getTimestamp()+432000));$out[$i]['cancelamento_em']=$deadline->format(DATE_ATOM);}
  return $out;
 }
 /** floor(value * part / total), avoiding overflow without float or optional extensions. */
 private static function proportion(int $value,int $part,int $total):int
 {
  $whole=intdiv($value,$total)*$part;$remainder=$value%$total;$quotient=$carry=0;
  foreach(str_split(decbin($part)) as $bit){$quotient*=2;$carry*=2;if($carry>=$total){$quotient++;$carry-=$total;}if($bit==='1'){$carry+=$remainder;if($carry>=$total){$quotient++;$carry-=$total;}}}
  return $whole+$quotient;
 }
}
