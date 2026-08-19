<?php
$resolverFoto = static function (?string $foto, int $indice = 0): string {
    $foto = ltrim(str_replace('\\', '/', trim((string) $foto)), '/');
    $arquivoAssets = $foto !== '' ? APP_ROOT . '/public/assets/' . $foto : '';
    $arquivoPublico = $foto !== '' ? APP_ROOT . '/public/' . $foto : '';

    if ($arquivoAssets !== '' && is_file($arquivoAssets)) {
        return asset($foto);
    }

    return $arquivoPublico !== '' && is_file($arquivoPublico)
        ? url('/' . $foto)
        : asset('img/chacara-' . (($indice % 6) + 1) . '.jpg');
};

$tipoLabels = [
    'chacara' => 'Chacara',
    'sitio' => 'Sitio',
    'area_lazer' => 'Area de lazer',
];
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
?>

<header class="panel-page-heading">
    <div>
        <span>Area do cliente</span>
        <h1>Favoritos</h1>
        <p>Acesse rapidamente os imoveis que voce salvou para consultar depois.</p>
    </div>
</header>

<section class="client-history-list">
    <?php if (empty($favoritos)): ?>
        <article class="panel-card client-empty-state">
            <strong>Nenhum favorito salvo</strong>
            <p>Toque no coracao dos cards para guardar chacaras, sitios e areas de lazer nesta lista.</p>
            <a class="btn btn-primary" href="<?= url('/#chacaras') ?>">Encontrar imoveis</a>
        </article>
    <?php endif; ?>

    <?php foreach ($favoritos as $indice => $favorito): ?>
        <?php
            $foto = $resolverFoto($favorito['foto'] ?? '', $indice);
            $status = (string) ($favorito['status'] ?? '');
            $disponivel = $status === 'disponivel';
        ?>
        <article class="client-reservation-card">
            <img src="<?= e($foto) ?>" alt="Foto de <?= e($favorito['nome']) ?>">
            <div class="client-reservation-content">
                <div>
                    <span class="client-card-kicker">
                        <?= e($tipoLabels[$favorito['tipo_imovel'] ?? ''] ?? 'Imovel') ?>
                        <?= !empty($favorito['cidade']) ? ' · ' . e($favorito['cidade']) : '' ?>
                        <?= !empty($favorito['regiao']) ? ' · ' . e($favorito['regiao']) : '' ?>
                    </span>
                    <h2><?= e($favorito['nome']) ?></h2>
                </div>

                <dl class="client-reservation-meta">
                    <div><dt>Diaria</dt><dd>R$ <?= e(number_format((float) $favorito['valor_diaria'], 2, ',', '.')) ?></dd></div>
                    <div><dt>Status</dt><dd><?= e($formatarStatus($status)) ?></dd></div>
                    <div><dt>Favoritado em</dt><dd><?= e(date('d/m/Y', strtotime((string) $favorito['data_favorito']))) ?></dd></div>
                </dl>

                <div class="client-card-footer">
                    <div class="client-status-group">
                        <span class="status-pill"><?= e($disponivel ? 'Disponivel' : $formatarStatus($status)) ?></span>
                    </div>
                    <div class="client-favorite-actions">
                        <?php if ($disponivel): ?>
                            <a class="btn btn-primary" href="<?= url('/chacara/' . (int) $favorito['id']) ?>">Ver Detalhes</a>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/chacara/' . (int) $favorito['id'] . '/favorito') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_to" value="<?= e(url('/cliente/favoritos')) ?>">
                            <button class="btn btn-outline" type="submit">Remover</button>
                        </form>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
