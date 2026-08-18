<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/scripts/bootstrap.php';

use App\Core\Database;
use App\Helpers\GoogleMapsHelper;
use App\Models\Chacara;
use App\Models\User;
use App\Validators\ChacaraLocationValidator;

$db = Database::getConnection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'alugfacil_dev') {
    throw new RuntimeException('Teste de localizacao permitido somente em alugfacil_dev.');
}

$checks = [];
$userId = 0;
$ownerId = 0;
$chacaraId = 0;

$throws = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};

try {
    $column = $db->query(
        "SELECT data_type, character_maximum_length, is_nullable
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'chacaras' AND column_name = 'estado'"
    )->fetch();
    $checks['schema_estado_char_2_anulavel'] = ($column['data_type'] ?? null) === 'character'
        && (int) ($column['character_maximum_length'] ?? 0) === 2
        && ($column['is_nullable'] ?? null) === 'YES';

    $checks['uf_valida_aceita_e_normalizada'] = ChacaraLocationValidator::normalizarEstado(' sp ') === 'SP';
    $checks['uf_invalida_rejeitada'] = $throws(
        static fn () => ChacaraLocationValidator::normalizarEstado('XX')
    );
    $checks['latitude_fora_da_faixa_rejeitada'] = $throws(
        static fn () => ChacaraLocationValidator::normalizarCoordenada('90.0001', -90, 90, 'latitude')
    );
    $checks['longitude_fora_da_faixa_rejeitada'] = $throws(
        static fn () => ChacaraLocationValidator::normalizarCoordenada('-180.0001', -180, 180, 'longitude')
    );

    $tag = bin2hex(random_bytes(5));
    $userModel = new User();
    $userId = $userModel->createOwner([
        'nome' => 'Proprietario Localizacao',
        'telefone' => '11999999999',
        'email' => "owner-location-{$tag}@localhost.test",
        'senha' => 'TesteLocal123!',
    ]);
    $ownerId = (int) ($userModel->findOwnerByUserId($userId)['id'] ?? 0);

    $dados = [
        'nome' => 'Chacara Localizacao',
        'descricao' => 'Teste automatizado de localizacao.',
        'tipo_imovel' => 'chacara',
        'valor_diaria' => '250.00',
        'cidade' => 'Sao Paulo',
        'estado' => 'SP',
        'regiao' => 'Centro',
        'endereco' => 'Praca da Se, Sao Paulo - SP',
        'latitude' => '-23.5505200',
        'longitude' => '-46.6333080',
        'checkin_hora_inicial' => '14:00',
        'checkin_hora_final' => '18:00',
        'checkout_hora_inicial' => '08:00',
        'checkout_hora_final' => '11:00',
    ];

    $model = new Chacara();
    $chacaraId = $model->criarParaProprietario($ownerId, $dados);
    $criada = $model->buscarDoProprietario($chacaraId, $ownerId);
    $checks['criacao_persiste_estado_e_coordenadas'] = ($criada['estado'] ?? null) === 'SP'
        && (float) ($criada['latitude'] ?? 0) === -23.55052
        && (float) ($criada['longitude'] ?? 0) === -46.633308;
    $checks['criacao_preserva_fluxo_de_aprovacao'] = ($criada['status_aprovacao'] ?? null) === 'pendente'
        && ($criada['status_operacional'] ?? null) === 'indisponivel';

    $dados['cidade'] = 'Belo Horizonte';
    $dados['estado'] = 'MG';
    $dados['endereco'] = 'Praca Sete, Belo Horizonte - MG';
    $dados['latitude'] = '-19.9190520';
    $dados['longitude'] = '-43.9386680';
    $model->atualizarDoProprietario($chacaraId, $ownerId, $dados);
    $atualizada = $model->buscarDoProprietario($chacaraId, $ownerId);
    $checks['edicao_persiste_estado_e_coordenadas'] = ($atualizada['estado'] ?? null) === 'MG'
        && (float) ($atualizada['latitude'] ?? 0) === -19.919052
        && (float) ($atualizada['longitude'] ?? 0) === -43.938668;

    $fotoTeste = 'uploads/chacaras/location-flow-test.jpg';
    $fotoId = $model->adicionarFoto($chacaraId, $fotoTeste);
    $fotos = $model->buscarFotosGerenciamento($chacaraId);
    $checks['fluxo_de_fotos_permanece_funcional'] = count($fotos) === 1
        && (int) ($fotos[0]['id'] ?? 0) === $fotoId
        && ($fotos[0]['caminho_foto'] ?? null) === $fotoTeste
        && $model->removerFoto($chacaraId, $fotoId) === $fotoTeste
        && $model->buscarFotosGerenciamento($chacaraId) === [];
    $checks['consulta_de_disponibilidade_permanece_funcional'] = $model->buscarDatasIndisponiveis(
        $chacaraId,
        '2099-01-01',
        '2099-01-10'
    ) === [];

    $db->prepare('UPDATE chacaras SET estado = NULL WHERE id = :id')->execute(['id' => $chacaraId]);
    $legada = $model->buscarDoProprietario($chacaraId, $ownerId);
    $checks['registro_legado_sem_estado_continua_valido'] = array_key_exists('estado', $legada) && $legada['estado'] === null;

    $maps = new GoogleMapsHelper();
    $apiKey = new ReflectionProperty($maps, 'apiKey');
    $apiKey->setValue($maps, '');
    $checks['helper_sem_chave_nao_gera_embed'] = $maps->embedUrl('-23.5', '-46.6') === null;
    $apiKey->setValue($maps, 'test-key');
    $checks['helper_sem_coordenadas_nao_gera_embed'] = $maps->embedUrl(null, null) === null;
    $embedUrl = $maps->embedUrl('-23.5505200', '-46.6333080', 99);
    parse_str((string) parse_url((string) $embedUrl, PHP_URL_QUERY), $embedQuery);
    $checks['helper_coordenadas_validas_geram_url'] = str_starts_with((string) $embedUrl, 'https://www.google.com/maps/embed/v1/view?')
        && ($embedQuery['center'] ?? null) === '-23.55052,-46.633308';
    $checks['helper_zoom_respeita_limites'] = ($embedQuery['zoom'] ?? null) === '21';

    $form = (string) file_get_contents(dirname(__DIR__) . '/app/views/proprietario/chacaras/form.php');
    $positions = array_map(
        static fn (string $name): int|false => strpos($form, 'name="' . $name . '"'),
        ['endereco', 'cidade', 'estado', 'regiao', 'latitude', 'longitude']
    );
    $checks['formulario_respeita_ordem_da_localizacao'] = !in_array(false, $positions, true);
    if ($checks['formulario_respeita_ordem_da_localizacao']) {
        $ordenadas = $positions;
        sort($ordenadas);
        $checks['formulario_respeita_ordem_da_localizacao'] = $positions === $ordenadas;
    }
    $checks['formulario_sem_chave_degrada_com_seguranca'] = str_contains(
        $form,
        "data-geocode-button <?= \$googleMapsConfigurado ? '' : 'disabled' ?>"
    ) && str_contains($form, 'informe a localiza&ccedil;&atilde;o manualmente');

    $mapsJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/maps.js');
    $checks['autocomplete_oficial_restrito_ao_brasil'] = str_contains($mapsJs, 'AutocompleteSuggestion.fetchAutocompleteSuggestions')
        && str_contains($mapsJs, "includedRegionCodes: ['br']")
        && str_contains($mapsJs, 'AutocompleteSessionToken')
        && str_contains($mapsJs, "'administrative_area_level_2'");
    $checks['fallback_nao_usa_endpoint_publico_com_chave'] = !str_contains($mapsJs, '/maps/api/geocode/json');

    $publicProfile = (string) file_get_contents(dirname(__DIR__) . '/app/views/public/perfil_chacara.php');
    $checks['perfil_publico_mantem_estados_do_mapa'] = str_contains($publicProfile, 'Localização não informada.')
        && str_contains($publicProfile, 'Mapa indisponível no momento.')
        && !str_contains($publicProfile, '<?= e($chacara[\'latitude\'])')
        && !str_contains($publicProfile, '<?= e($chacara[\'longitude\'])');
} finally {
    if ($chacaraId > 0) {
        $db->prepare('DELETE FROM chacaras WHERE id = :id')->execute(['id' => $chacaraId]);
    }
    if ($ownerId > 0) {
        $db->prepare('DELETE FROM proprietarios WHERE id = :id')->execute(['id' => $ownerId]);
    }
    if ($userId > 0) {
        $db->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $userId]);
    }
}

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
