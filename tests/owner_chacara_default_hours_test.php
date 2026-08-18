<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Validators\ChacaraHoursValidator;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$invalid = static function (array $dados, string $message): bool {
    try {
        ChacaraHoursValidator::validar($dados);
        return false;
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage() === $message;
    }
};

$defaults = ChacaraHoursValidator::normalizar([
    'checkin_hora_inicial' => '',
    'checkin_hora_final' => '   ',
    'checkout_hora_inicial' => null,
]);
$check('01 criacao vazia recebe os quatro defaults', $defaults === [
    'checkin_hora_inicial' => '14:00',
    'checkin_hora_final' => '18:00',
    'checkout_hora_inicial' => '08:00',
    'checkout_hora_final' => '11:00',
]);
ChacaraHoursValidator::validar($defaults);
$check('02 defaults gerados possuem formato e ordem validos', true);

$custom = ChacaraHoursValidator::normalizar([
    'checkin_hora_inicial' => '15:30',
    'checkin_hora_final' => '21:00',
    'checkout_hora_inicial' => '07:15',
    'checkout_hora_final' => '12:45',
]);
$check('03 criacao personalizada preserva valores informados', $custom === [
    'checkin_hora_inicial' => '15:30',
    'checkin_hora_final' => '21:00',
    'checkout_hora_inicial' => '07:15',
    'checkout_hora_final' => '12:45',
]);
ChacaraHoursValidator::validar($custom);

$mixed = ChacaraHoursValidator::normalizar([
    'checkin_hora_inicial' => ' ',
    'checkin_hora_final' => '20:00',
    'checkout_hora_inicial' => '',
    'checkout_hora_final' => '12:00',
]);
$check('04 edicao aplica default somente aos campos apagados', $mixed === [
    'checkin_hora_inicial' => '14:00',
    'checkin_hora_final' => '20:00',
    'checkout_hora_inicial' => '08:00',
    'checkout_hora_final' => '12:00',
]);
ChacaraHoursValidator::validar($mixed);

$badFormat = array_replace($defaults, ['checkin_hora_final' => '25:00']);
$check('05 formato invalido continua rejeitado', $invalid($badFormat, 'Informe todos os horarios da hospedagem.'));
$badOrder = array_replace($defaults, ['checkin_hora_inicial' => '18:00', 'checkin_hora_final' => '14:00']);
$check('06 ordem invalida continua rejeitada', $invalid($badOrder, 'O horario inicial deve ser anterior ao horario final.'));

$_SESSION = [];
prepare_old_input_flash();
set_old($mixed);
prepare_old_input_flash();
$check('07 old input recebe os valores normalizados no redirect', old('checkin_hora_inicial') === '14:00' && old('checkin_hora_final') === '20:00');
prepare_old_input_flash();
$check('08 old input de horarios e consumido no request posterior', old('checkin_hora_inicial', 'persistido') === 'persistido');

$controller = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/OwnerChacaraController.php');
$model = (string) file_get_contents(dirname(__DIR__) . '/app/models/Chacara.php');
$form = (string) file_get_contents(dirname(__DIR__) . '/app/views/proprietario/chacaras/form.php');
$check('09 controller normaliza antes de set_old e validacao', strpos($controller, 'ChacaraHoursValidator::normalizar') < strpos($controller, 'set_old($dados)') && strpos($controller, 'set_old($dados)') < strpos($controller, 'ChacaraHoursValidator::validar'));
$check('10 criacao e edicao persistem os quatro horarios normalizados', str_contains($model, "'ci'=>\$dados['checkin_hora_inicial']") && str_contains($model, "'cof'=>\$dados['checkout_hora_final']"));
$check('11 formulario mantem defaults visuais e old na edicao', str_contains($form, "'checkin_hora_inicial','14:00'") && str_contains($form, 'old($key,'));

echo "Total: " . ($passed + $failed) . " | Aprovados: {$passed} | Falhos: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
