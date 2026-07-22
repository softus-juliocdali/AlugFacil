<?php
$resolverFoto = static function (?string $foto, int $indice = 0): string {
    $foto = trim(str_replace('\\', '/', (string) $foto));
    $arquivo = $foto !== '' ? APP_ROOT . '/public/' . ltrim($foto, '/') : '';
    return $arquivo !== '' && is_file($arquivo)
        ? url('/' . ltrim($foto, '/'))
        : asset('img/chacara-' . (($indice % 6) + 1) . '.jpg');
};

$foto = $resolverFoto($chacara['foto'] ?? $chacara['foto_principal'] ?? '', ((int) $chacara['id'] - 1));
$valorDiaria = (float) $chacara['valor_diaria'];
?>

<section class="profile-page reservation-page">
    <div class="container">
        <nav class="profile-breadcrumb" aria-label="Navegação estrutural">
            <a href="<?= url('/') ?>">Início</a><span>›</span>
            <a href="<?= url('/chacara/' . (int) $chacara['id']) ?>"><?= e($chacara['nome']) ?></a><span>›</span>
            <strong>Reservar</strong>
        </nav>

        <header class="profile-heading reservation-heading">
            <div>
                <span class="profile-kicker">Fluxo de reserva</span>
                <h1>Reserve <?= e($chacara['nome']) ?></h1>
                <p>Escolha o período da estadia e confira o total antes de confirmar.</p>
            </div>
        </header>

        <div class="reservation-layout">
            <article class="reservation-property-card">
                <img src="<?= e($foto) ?>" alt="Foto principal de <?= e($chacara['nome']) ?>">
                <div>
                    <span class="section-kicker">Chácara selecionada</span>
                    <h2><?= e($chacara['nome']) ?></h2>
                    <p><?= e(implode(' · ', array_filter([$chacara['cidade'], $chacara['regiao']]))) ?></p>
                    <div class="booking-price"><strong>R$ <?= e(number_format($valorDiaria, 2, ',', '.')) ?></strong><small>/ diária</small></div>
                </div>
            </article>

            <form class="reservation-form" action="<?= url('/reserva/criar/' . (int) $chacara['id']) ?>" method="post" data-reservation-form>
                <?= csrf_field() ?>
                <input type="hidden" data-daily-rate value="<?= e(number_format($valorDiaria, 2, '.', '')) ?>">

                <div class="reservation-form-grid">
                    <label>
                        Data inicial
                        <input type="date" name="data_inicio" value="<?= e($dataInicio) ?>" min="<?= e($minDataInicio) ?>" required data-reservation-start>
                    </label>
                    <label>
                        Data final
                        <input type="date" name="data_fim" value="<?= e($dataFim) ?>" min="<?= e($minDataInicio) ?>" required data-reservation-end>
                    </label>
                </div>

                <div class="reservation-summary">
                    <div>
                        <span>Quantidade de diárias</span>
                        <strong><span data-reservation-nights><?= e((string) $quantidadeDiarias) ?></span></strong>
                    </div>
                    <div>
                        <span>Valor da diária</span>
                        <strong>R$ <?= e(number_format($valorDiaria, 2, ',', '.')) ?></strong>
                    </div>
                    <div class="reservation-total">
                        <span>Valor total</span>
                        <strong data-reservation-total>R$ <?= e(number_format($valorTotal, 2, ',', '.')) ?></strong>
                    </div>
                </div>

                <p class="reservation-note">A reserva ficará como aguardando pagamento e será confirmada após a compensação.</p>
                <button class="btn btn-book" type="submit">Confirmar Reserva</button>
            </form>
        </div>
    </div>
</section>
