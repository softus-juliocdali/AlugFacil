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
$formatarStatusAprovacao = static fn (string $status): string => match ($status) {
    'pendente' => 'Aguardando aprovação',
    'aprovada' => 'Aprovada',
    'rejeitada' => 'Reprovada',
    default => ucfirst(str_replace('_', ' ', $status)),
};
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
    <form class="owner-property-form" method="post" action="<?= e($action) ?>" data-map-geocoder>
        <?= csrf_field() ?>

        <label>
            Nome da ch&aacute;cara
            <input type="text" name="nome" value="<?= e($value('nome')) ?>" minlength="3" maxlength="180" required>
        </label>

        <label>
            Valor da di&aacute;ria
            <input type="number" name="valor_diaria" value="<?= e($value('valor_diaria')) ?>" min="0.01" step="0.01" required>
            <small>Valor que voc&ecirc; deseja receber por di&aacute;ria. As taxas de servi&ccedil;o e processamento ser&atilde;o adicionadas ao valor pago pelo cliente.</small>
        </label>

        <label>
            Tipo de im&oacute;vel
            <select name="tipo_imovel" required>
                <?php foreach (($tiposImovel ?? array_keys($tiposLabels)) as $tipo): ?>
                    <option value="<?= e($tipo) ?>" <?= $value('tipo_imovel', 'chacara') === $tipo ? 'selected' : '' ?>><?= $tiposLabels[$tipo] ?? e($tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-full"><h2>Hor&aacute;rios da hospedagem</h2></div>
        <label>Check-in &mdash; a partir das<input type="time" name="checkin_hora_inicial" value="<?= e($value('checkin_hora_inicial','14:00')) ?>" required></label>
        <label>Check-in &mdash; at&eacute; as<input type="time" name="checkin_hora_final" value="<?= e($value('checkin_hora_final','18:00')) ?>" required></label>
        <label>Check-out &mdash; a partir das<input type="time" name="checkout_hora_inicial" value="<?= e($value('checkout_hora_inicial','08:00')) ?>" required></label>
        <label>Check-out &mdash; at&eacute; as<input type="time" name="checkout_hora_final" value="<?= e($value('checkout_hora_final','11:00')) ?>" required></label>

        <label class="form-full">
            Descri&ccedil;&atilde;o
            <textarea name="descricao" rows="5"><?= e($value('descricao')) ?></textarea>
        </label>

        <div class="form-full"><h2>Localiza&ccedil;&atilde;o</h2></div>
        <label class="form-full">
            Endere&ccedil;o
            <input type="text" name="endereco" value="<?= e($value('endereco')) ?>" maxlength="255" autocomplete="street-address" aria-autocomplete="list" aria-controls="owner-address-suggestions" data-address-autocomplete required>
            <div class="owner-address-suggestions" id="owner-address-suggestions" role="listbox" data-address-suggestions hidden></div>
            <small><?= $googleMapsConfigurado ? 'Comece a digitar e selecione uma sugest&atilde;o para preencher a localiza&ccedil;&atilde;o.' : 'O preenchimento autom&aacute;tico est&aacute; indispon&iacute;vel; informe a localiza&ccedil;&atilde;o manualmente.' ?></small>
        </label>

        <label>
            Cidade
            <input type="text" name="cidade" value="<?= e($value('cidade')) ?>" maxlength="100" autocomplete="address-level2" data-city-input required>
        </label>

        <label>
            Estado (UF)
            <input type="text" name="estado" value="<?= e($value('estado')) ?>" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="address-level1" inputmode="text" data-state-input aria-describedby="estado-ajuda">
            <small id="estado-ajuda">Sigla brasileira com duas letras, como SP ou MG.</small>
        </label>

        <label>
            Regi&atilde;o
            <input type="text" name="regiao" value="<?= e($value('regiao')) ?>" maxlength="100" data-region-input>
        </label>

        <div class="form-full owner-map-tools">
            <div class="owner-map-tools-heading">
                <div>
                    <strong>Localiza&ccedil;&atilde;o no mapa</strong>
                    <p>As coordenadas s&atilde;o preenchidas pela sugest&atilde;o de endere&ccedil;o. Use o bot&atilde;o apenas como recupera&ccedil;&atilde;o.</p>
                </div>
                <button class="btn btn-outline btn-small" type="button" data-geocode-button <?= $googleMapsConfigurado ? '' : 'disabled' ?>>Recalcular coordenadas</button>
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
            <p class="owner-map-feedback" data-geocode-feedback aria-live="polite"><?= $googleMapsConfigurado ? 'Selecione uma sugest&atilde;o de endere&ccedil;o ou use o rec&aacute;lculo quando necess&aacute;rio.' : 'Localiza&ccedil;&atilde;o autom&aacute;tica indispon&iacute;vel: configure GOOGLE_MAPS_API_KEY no ambiente.' ?></p>
        </div>

        <label>
            Status de aprova&ccedil;&atilde;o
            <input type="text" value="<?= e($formatarStatusAprovacao((string) ($chacara['status_aprovacao'] ?? 'pendente'))) ?>" disabled>
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
