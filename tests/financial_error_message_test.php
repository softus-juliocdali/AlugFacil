<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Services\FinancialErrorMessage;
$fallback='Operacao indisponivel.';
$cases=[
    [new RuntimeException('Condicao invalida.'),'Condicao invalida.'],
    [new DomainException('Sem permissao.'),'Sem permissao.'],
    [new PDOException('SQLSTATE synthetic private query'),$fallback],
    [new App\Services\AsaasApiException('synthetic private gateway payload',400),$fallback],
    [new TypeError('synthetic internal path'),$fallback],
    [new Exception('synthetic internal failure'),$fallback],
];
foreach($cases as [$error,$expected]) {
    if(FinancialErrorMessage::publicMessage($error,$fallback)!==$expected)throw new RuntimeException('Exposicao de erro interno.');
    echo '[OK] Mensagem segura: '.get_class($error).PHP_EOL;
}
$filtered=App\Services\FinancialPayloadFilter::sanitize(['id'=>'fixture','headers'=>['Authorization'=>'synthetic-private','Proxy-Authorization'=>'synthetic-proxy','safe'=>'preserved']]);
if($filtered!==['id'=>'fixture','headers'=>['safe'=>'preserved']])throw new RuntimeException('Cabecalho privado persistido.');
echo '[OK] Cabecalhos de autorizacao removidos recursivamente do payload.'.PHP_EOL;
