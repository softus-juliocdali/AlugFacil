<?php
declare(strict_types=1);require dirname(__DIR__).'/scripts/bootstrap.php';use App\Services\AsaasSubcontaService;use App\Services\FakeAsaasClient;
$ok=0;$fail=0;$test=function(string$n,callable$f)use(&$ok,&$fail){try{$f();echo"[OK] $n\n";$ok++;}catch(Throwable$e){echo"[FALHA] $n: {$e->getMessage()}\n";$fail++;}};$invalid=function(array$d,string$msg){try{AsaasSubcontaService::validarDados($d);}catch(Throwable$e){if(str_contains($e->getMessage(),$msg))return;}throw new RuntimeException('Validacao esperada nao ocorreu.');};
$base=['tipo_pessoa'=>'PF','cpf_cnpj'=>'52998224725','nome_razao_social'=>'Teste','data_nascimento'=>'1990-01-01','tipo_empresa'=>'','renda_faturamento_mensal'=>'1000,00','telefone'=>'1133334444','celular'=>'11999998888','email_financeiro'=>'teste@invalid.local','cep'=>'01001000','endereco'=>'Rua Teste','numero'=>'1','complemento'=>'','bairro'=>'Centro','cidade'=>'Sao Paulo','estado'=>'SP','nome_fantasia'=>''];
$test('cpf invalido',fn()=>$invalid(array_replace($base,['cpf_cnpj'=>'11111111111']),'CPF invalido'));
$test('email invalido',fn()=>$invalid(array_replace($base,['email_financeiro'=>'x']),'incompletos'));
$test('cep invalido',fn()=>$invalid(array_replace($base,['cep'=>'123']),'incompletos'));
$test('telefone invalido',fn()=>$invalid(array_replace($base,['telefone'=>'123']),'Telefone invalido'));
$test('renda negativa',fn()=>$invalid(array_replace($base,['renda_faturamento_mensal'=>'-1']),'incompletos'));
$test('payload PF sem companyType',function()use($base){$s=new AsaasSubcontaService(new FakeAsaasClient());$p=$s->payload(AsaasSubcontaService::validarDados($base));if(isset($p['companyType'])||!isset($p['birthDate']))throw new RuntimeException('Payload incorreto.');});
$test('apiKey fake nao compoe payload',function()use($base){$s=new AsaasSubcontaService(new FakeAsaasClient());if(str_contains(json_encode($s->payload(AsaasSubcontaService::validarDados($base))),'apiKey'))throw new RuntimeException('Segredo presente.');});
echo"ok=$ok falhas=$fail\n";exit($fail?1:0);
