<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$nextRequest = static function (): void {
    prepare_old_input_flash();
};

$_SESSION = [];
$nextRequest();
$check('01 novo cadastro inicia sem nome antigo', old('nome') === '');
$check('02 novo cadastro inicia sem localizacao antiga', old('endereco') === '' && old('cidade') === '' && old('estado') === '' && old('latitude') === '' && old('longitude') === '');

set_old([
    'nome' => 'Chacara digitada',
    'valor_diaria' => '350.00',
    'endereco' => 'Rua do retorno, 10',
    'cidade' => 'Sorocaba',
    'estado' => 'SP',
]);
$check('03 set_old nao contamina o request atual', old('nome') === '');

$nextRequest();
$check('04 old fica disponivel no request do redirect', old('nome') === 'Chacara digitada' && old('cidade') === 'Sorocaba');
$check('05 old permanece estavel durante todo o request', old('nome') === 'Chacara digitada' && old('nome') === 'Chacara digitada');

$nextRequest();
$check('06 request posterior consome os dados antigos', old('nome') === '' && old('cidade') === '');
$check('07 formulario de edicao usa valor persistido sem old', old('nome', 'Chacara do banco') === 'Chacara do banco');

set_old(['nome' => 'Correcao temporaria']);
$nextRequest();
$check('08 edicao com erro prioriza old temporario', old('nome', 'Chacara do banco') === 'Correcao temporaria');
$nextRequest();
$check('09 edicao volta ao banco apos consumo', old('nome', 'Chacara do banco') === 'Chacara do banco');

set_old(['nome' => 'Nao deve sobreviver']);
clear_old();
$nextRequest();
$check('10 sucesso limpa old atual e pendente', old('nome') === '' && !isset($_SESSION['_old_next']));

$helpers = (string) file_get_contents(dirname(__DIR__) . '/app/helpers/functions.php');
$index = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$form = (string) file_get_contents(dirname(__DIR__) . '/app/views/proprietario/chacaras/form.php');
$check('11 lifecycle e centralizado no helper e bootstrap web', str_contains($helpers, "\$_SESSION['_old_next']") && str_contains($index, 'prepare_old_input_flash();'));
$check('12 formulario preserva old sem limpeza fragil no controller', str_contains($form, 'old($key,') && !str_contains((string) file_get_contents(dirname(__DIR__) . '/app/controllers/OwnerChacaraController.php'), 'public function create(): void' . PHP_EOL . '    {' . PHP_EOL . '        clear_old();'));

echo "Total: " . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
