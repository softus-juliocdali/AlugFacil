<?php if ($erroFiltro !== null || $erroBanco !== null): ?>
    <div class="listings-empty" role="alert">
        <span>!</span>
        <h3>Não conseguimos concluir a busca</h3>
        <p><?= e($erroFiltro ?? $erroBanco) ?></p>
    </div>
<?php elseif ($properties === []): ?>
    <div class="listings-empty">
        <span>⌕</span>
        <h3>Nenhuma chácara encontrada</h3>
        <p>Tente ampliar a faixa de preço, mudar a localização ou escolher outras datas.</p>
        <a class="btn btn-primary" href="<?= url('/#chacaras') ?>">Limpar filtros</a>
    </div>
<?php else: ?>
    <div class="property-grid">
        <?php foreach ($properties as $index => $property): ?>
            <?php
                $foto = trim((string) ($property['foto'] ?? ''));
                $foto = ltrim(str_replace('\\', '/', $foto), '/');
                $arquivoAssets = $foto !== '' ? APP_ROOT . '/public/assets/' . $foto : '';
                $arquivoPublico = $foto !== '' ? APP_ROOT . '/public/' . $foto : '';
                if ($arquivoAssets !== '' && is_file($arquivoAssets)) {
                    $fotoUrl = asset($foto);
                } elseif ($arquivoPublico !== '' && is_file($arquivoPublico)) {
                    $fotoUrl = url('/' . $foto);
                } else {
                    $fotoUrl = asset('img/chacara-' . (($index % 6) + 1) . '.jpg');
                }
                $avaliacao = (float) ($property['avaliacao'] ?? 0);
                $favoritado = in_array((int) $property['id'], $favoriteChacaraIds ?? [], true);
                $retorno = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                $retorno .= str_contains($retorno, '#') ? '' : '#chacaras';
            ?>
            <article class="property-card">
                <div class="property-image">
                    <img src="<?= e($fotoUrl) ?>" alt="Foto principal de <?= e($property['nome']) ?>" loading="lazy">
                    <form class="favorite-form" method="post" action="<?= url('/chacara/' . (int) $property['id'] . '/favorito') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="<?= e($retorno) ?>">
                        <button class="heart-button<?= $favoritado ? ' is-favorite' : '' ?>" type="submit" aria-label="<?= $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>" aria-pressed="<?= $favoritado ? 'true' : 'false' ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>
                        </button>
                    </form>
                    <span class="availability-badge">Disponível</span>
                </div>
                <div class="property-body">
                    <h3><?= e($property['nome']) ?></h3>
                    <p class="property-location">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <?= e($property['cidade']) ?>
                    </p>
                    <p class="property-region"><?= e($property['regiao'] ?: 'Região não informada') ?></p>
                </div>
                <div class="property-footer">
                    <div>
                        <small>A partir de</small>
                        <strong>R$ <?= e(number_format((float) $property['valor_diaria'], 2, ',', '.')) ?> <em>/ diária</em></strong>
                    </div>
                    <?php if ($avaliacao > 0): ?>
                        <span class="rating">★ <b><?= e(number_format($avaliacao, 1, ',', '.')) ?></b> (<?= e($property['total_avaliacoes']) ?>)</span>
                    <?php endif; ?>
                </div>
                <div class="property-description" id="detalhes-<?= e($property['id']) ?>" hidden>
                    <?= e($property['descricao'] ?: 'Consulte as condições e datas disponíveis para esta chácara.') ?>
                </div>
                <a class="property-details" href="<?= url('/chacara/' . (int) $property['id']) ?>">
                    Ver Detalhes
                </a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
