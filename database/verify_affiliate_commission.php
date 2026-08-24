<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;

$db=Database::getConnection();$ok=0;$fail=0;
$check=static function(string $name,bool $condition)use(&$ok,&$fail):void{echo($condition?'[OK] ':'[FALHA] ').$name.PHP_EOL;$condition?$ok++:$fail++;};
$tables=$db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename IN ('comissoes_afiliados','pagamentos_afiliados','alocacoes_pagamentos_afiliados','ajustes_afiliados')")->fetchAll(PDO::FETCH_COLUMN);
$check('quatro estruturas financeiras existem',count($tables)===4);
$constraints=$db->query("SELECT conname FROM pg_constraint WHERE conname IN ('uq_comissao_cobranca_mensalidade','uq_comissao_asaas_payment','uq_alocacao_pagamento_comissao','uq_ajuste_estorno_comissao')")->fetchAll(PDO::FETCH_COLUMN);
$check('origem, alocacao e ajuste possuem unicidade',count($constraints)===4);
$check('nenhuma comissao duplicada por cobranca',(int)$db->query('SELECT COUNT(*) FROM (SELECT cobranca_mensalidade_id FROM comissoes_afiliados GROUP BY 1 HAVING COUNT(*)>1) x')->fetchColumn()===0);
$check('nenhuma alocacao excede a comissao',(int)$db->query('SELECT COUNT(*) FROM comissoes_afiliados c WHERE (SELECT COALESCE(SUM(a.valor_centavos),0) FROM alocacoes_pagamentos_afiliados a WHERE a.comissao_afiliado_id=c.id)>c.valor_comissao_centavos')->fetchColumn()===0);
$check('periodo de liberacao e exatamente sete dias',(int)$db->query("SELECT COUNT(*) FROM comissoes_afiliados WHERE disponivel_em<>confirmado_em+INTERVAL '7 days'")->fetchColumn()===0);
echo 'Total: '.($ok+$fail)." | Aprovados: $ok | Falhos: $fail".PHP_EOL;exit($fail?1:0);
