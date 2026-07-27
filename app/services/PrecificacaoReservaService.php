<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
final class PrecificacaoReservaService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public static function decimalParaCentavos(string$v):int{$v=trim(str_replace(',','.',$v));if(!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/',$v,$m))throw new RuntimeException('Valor monetario invalido.');return((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');}
 public static function centavosParaDecimal(int$v):string{if($v<0)throw new RuntimeException('Valor monetario negativo.');return intdiv($v,100).'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);}
 public static function diarias(string$i,string$f):int{$a=new DateTimeImmutable($i);$b=new DateTimeImmutable($f);$n=(int)$a->diff($b)->format('%r%a');if($n<1)throw new RuntimeException('Periodo invalido.');return$n;}
 public function cotar(int$diaria,int$diarias,string$forma='PIX',int$parcelas=1):array
 {if($diaria<=0||$diarias<1)throw new RuntimeException('Valor ou quantidade de diarias invalido.');if(strtoupper($forma)!=='PIX'||$parcelas!==1)throw new RuntimeException('Reservas aceitam exclusivamente pagamento PIX.');$c=$this->configuracao();$reserva=$diaria*$diarias;$taxa=(int)$c['taxa_operacao_pix_reserva_centavos'];return['quantidade_diarias'=>$diarias,'valor_diaria_liquido_proprietario_centavos'=>$diaria,'valor_hospedagem_centavos'=>$reserva,'valor_reserva_centavos'=>$reserva,'valor_liquido_proprietario_centavos'=>$reserva,'taxa_operacao_pix_centavos'=>$taxa,'taxa_plataforma_centavos'=>0,'taxa_gateway_estimada_centavos'=>0,'valor_total_cliente_centavos'=>$reserva+$taxa,'plataforma_percentual_bps'=>0,'plataforma_fixa_centavos'=>0,'gateway_percentual_bps'=>0,'gateway_fixa_centavos'=>0,'margem_seguranca_bps'=>0,'forma_pagamento'=>'PIX','quantidade_parcelas'=>1,'versao_precificacao'=>(int)$c['versao'],'configuracao_financeira_id'=>(int)$c['id'],'origem_precificacao'=>'pix_operacao_v1'];}
 public function configuracoesPagamento():array{return[['forma_pagamento'=>'PIX','quantidade_parcelas'=>1]];}
 public function taxaOperacaoPix():int{return(int)$this->configuracao()['taxa_operacao_pix_reserva_centavos'];}
 private function configuracao():array{$r=$this->db->query("SELECT id,versao,taxa_operacao_pix_reserva_centavos FROM configuracoes_financeiras WHERE vigencia_fim IS NULL AND precificacao_ativa ORDER BY versao DESC LIMIT 1")->fetch();if(!$r)throw new RuntimeException('Precificacao sem configuracao ativa.');return$r;}
}
