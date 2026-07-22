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

    public static function decimalParaCentavos(string $valor): int
    {
        $valor=trim(str_replace(',','.',$valor));
        if(!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/',$valor,$m))throw new RuntimeException('Valor monetario invalido.');
        $centavos=((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');
        if($centavos<0)throw new RuntimeException('Valor monetario negativo.');
        return $centavos;
    }

    public static function centavosParaDecimal(int $centavos): string
    {if($centavos<0)throw new RuntimeException('Valor monetario negativo.');return intdiv($centavos,100).'.'.str_pad((string)($centavos%100),2,'0',STR_PAD_LEFT);}
    public static function ceilBps(int $valor,int $bps):int{if($valor<0||$bps<0||$bps>=10000)throw new RuntimeException('Percentual ou valor invalido.');return intdiv($valor*$bps+9999,10000);}
    public static function diarias(string $inicio,string $fim):int{$a=new DateTimeImmutable($inicio);$b=new DateTimeImmutable($fim);$n=(int)$a->diff($b)->format('%r%a');if($n<1)throw new RuntimeException('Periodo invalido.');return $n;}

    public function cotar(int $diaria,int $diarias,string $forma,int $parcelas):array
    {
        if($diaria<=0||$diarias<1)throw new RuntimeException('Valor ou quantidade de diarias invalido.');
        $forma=strtoupper($forma);if(!in_array($forma,['PIX','CREDIT_CARD'],true))throw new RuntimeException('Forma de pagamento invalida.');
        if($forma==='PIX'&&$parcelas!==1)throw new RuntimeException('PIX nao permite parcelas.');
        $c=$this->configuracao($forma,$parcelas);$h=$diaria*$diarias;
        $plataforma=self::ceilBps($h,(int)$c['taxa_plataforma_percentual_bps'])+(int)$c['taxa_plataforma_fixa_centavos'];
        $bps=(int)$c['percentual_gateway_bps']+(int)$c['margem_seguranca_bps'];if($bps>=10000)throw new RuntimeException('Taxa efetiva do gateway invalida.');
        $base=$h+$plataforma;$lo=$base;$hi=max($base+1, self::divCeil(($base+(int)$c['taxa_fixa_gateway_centavos'])*10000,10000-$bps));
        while($this->liquido($hi,$bps,(int)$c['taxa_fixa_gateway_centavos'])<$base)$hi++;
        while($lo<$hi){$mid=intdiv($lo+$hi,2);if($this->liquido($mid,$bps,(int)$c['taxa_fixa_gateway_centavos']) >= $base)$hi=$mid;else$lo=$mid+1;}
        $gateway=self::ceilBps($lo,$bps)+(int)$c['taxa_fixa_gateway_centavos'];
        $r=['quantidade_diarias'=>$diarias,'valor_diaria_liquido_proprietario_centavos'=>$diaria,'valor_hospedagem_centavos'=>$h,'valor_liquido_proprietario_centavos'=>$h,'taxa_plataforma_centavos'=>$plataforma,'taxa_gateway_estimada_centavos'=>$gateway,'valor_total_cliente_centavos'=>$lo,'plataforma_percentual_bps'=>(int)$c['taxa_plataforma_percentual_bps'],'plataforma_fixa_centavos'=>(int)$c['taxa_plataforma_fixa_centavos'],'gateway_percentual_bps'=>(int)$c['percentual_gateway_bps'],'gateway_fixa_centavos'=>(int)$c['taxa_fixa_gateway_centavos'],'margem_seguranca_bps'=>(int)$c['margem_seguranca_bps'],'forma_pagamento'=>$forma,'quantidade_parcelas'=>$parcelas,'versao_precificacao'=>(int)$c['versao'],'configuracao_financeira_id'=>(int)$c['configuracao_id'],'origem_precificacao'=>'nova'];
        if($lo-$gateway<$h+$plataforma||($lo>$base&&$this->liquido($lo-1,$bps,(int)$c['taxa_fixa_gateway_centavos'])>=$base))throw new RuntimeException('Invariante financeira violada.');
        return $r;
    }

    public function configuracoesPagamento():array
    {$s=$this->db->query("SELECT t.forma_pagamento,t.quantidade_parcelas FROM configuracoes_financeiras c JOIN taxas_meios_pagamento t ON t.configuracao_financeira_id=c.id WHERE c.vigencia_fim IS NULL AND c.precificacao_ativa AND t.ativo ORDER BY t.forma_pagamento,t.quantidade_parcelas");return $s->fetchAll();}
    private function configuracao(string $forma,int $parcelas):array{$s=$this->db->prepare("SELECT c.id configuracao_id,c.*,t.* FROM configuracoes_financeiras c JOIN taxas_meios_pagamento t ON t.configuracao_financeira_id=c.id WHERE c.vigencia_fim IS NULL AND c.precificacao_ativa AND t.ativo AND t.forma_pagamento=:f AND t.quantidade_parcelas=:p LIMIT 1");$s->execute(['f'=>$forma,'p'=>$parcelas]);$r=$s->fetch();if(!$r)throw new RuntimeException('Precificacao ou meio de pagamento sem configuracao ativa.');return$r;}
    private function liquido(int $total,int $bps,int $fixa):int{return$total-self::ceilBps($total,$bps)-$fixa;}
    private static function divCeil(int $a,int $b):int{return intdiv($a+$b-1,$b);}
}
