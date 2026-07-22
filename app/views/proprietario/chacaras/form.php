<?php
$isEdit = is_array($chacara);
$value = static fn (string $key, string $default = ''): string => old($key, $isEdit ? (string) ($chacara[$key] ?? $default) : $default);
$googleMapsConfigurado = (bool) ($googleMapsConfigurado ?? false);
$googleMapsApiKey = (string) ($googleMapsApiKey ?? '');
$tiposLabels = [
    'chacara' => 'Ch&aacute;cara',
    'sitio' => 'S&iacute;tio',
    'area_lazer' => '&Aacute;rea de lazer',
];
?>
<div class="panel-page-heading">
    <div>
        <span>Gestao de chacaras</span>
        <h1><?= $isEdit ? 'Editar ch&aacute;cara' : 'Cadastrar ch&aacute;cara' ?></h1>
        <p>Preencha os dados principais do im&oacute;vel. A publica&ccedil;&atilde;o depende de aprova&ccedil;&atilde;o administrativa.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/proprietario/chacaras') ?>">Voltar</a>
</div>

<section class="panel-card owner-form-card">
    <form class="owner-property-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <label>
            Nome da ch&aacute;cara
            <input type="text" name="nome" value="<?= e($value('nome')) ?>" minlength="3" maxlength="180" required>
        </label>

        <label>
            Valor da di&aacute;ria
            <input type="number" name="valor_diaria" value="<?= e($value('valor_diaria')) ?>" min="0.01" step="0.01" required>
        </label>

        <label>
            Tipo de im&oacute;vel
            <select name="tipo_imovel" required>
                <?php foreach (($tiposImovel ?? array_keys($tiposLabels)) as $tipo): ?>
                    <option value="<?= e($tipo) ?>" <?= $value('tipo_imovel', 'chacara') === $tipo ? 'selected' : '' ?>><?= $tiposLabels[$tipo] ?? e($tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="form-full">
            Descri&ccedil;&atilde;o
            <textarea name="descricao" rows="5"><?= e($value('descricao')) ?></textarea>
        </label>

        <label>
            Cidade
            <input type="text" name="cidade" value="<?= e($value('cidade')) ?>" maxlength="100" required>
        </label>

        <label>
            Regi&atilde;o
            <input type="text" name="regiao" value="<?= e($value('regiao')) ?>" maxlength="100">
        </label>

        <label class="form-full">
            Endere&ccedil;o
            <input type="text" name="endereco" value="<?= e($value('endereco')) ?>" maxlength="255" required>
        </label>

        <div class="form-full owner-map-tools" data-map-geocoder>
            <div class="owner-map-tools-heading">
                <div>
                    <strong>Localiza&ccedil;&atilde;o no mapa</strong>
                    <p>Informe as coordenadas manualmente ou busque pelo endere&ccedil;o cadastrado.</p>
                </div>
                <button class="btn btn-outline btn-small" type="button" data-geocode-button <?= $googleMapsConfigurado ? '' : 'disabled' ?>>Buscar coordenadas</button>
            </div>
            <div class="owner-map-fields">
                <label>
                    Latitude
                    <input type="number" name="latitude" value="<?= e($value('latitude')) ?>" min="-90" max="90" step="0.0000001" data-latitude-input>
                </label>

                <label>
                    Longitude
                    <input type="number" name="longitude" value="<?= e($value('longitude')) ?>" min="-180" max="180" step="0.0000001" data-longitude-input>
                </label>
            </div>
            <p class="owner-map-feedback" data-geocode-feedback><?= $googleMapsConfigurado ? 'A busca usa cidade, regi&atilde;o e endere&ccedil;o para localizar o ponto.' : 'Busca por endere&ccedil;o indispon&iacute;vel: configure GOOGLE_MAPS_API_KEY em app/config/apis.php.' ?></p>
        </div>

        <label>
            Status de aprova&ccedil;&atilde;o
            <input type="text" value="<?= e(ucfirst((string) ($chacara['status_aprovacao'] ?? 'pendente'))) ?>" disabled>
        </label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Salvar ch&aacute;cara</button>
            <?php if ($isEdit): ?>
                <a class="btn btn-outline" href="<?= url('/proprietario/chacaras/fotos/' . (int) $chacara['id']) ?>">Gerenciar fotos</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<script src="<?= asset('js/maps.js') ?>" data-google-maps-key="<?= e($googleMapsApiKey) ?>"></script>
