<?php
$resolverFoto = static function (string $foto, int $indice = 0): string {
    $foto = trim(str_replace('\\', '/', $foto));
    $foto = ltrim($foto, '/');
    $arquivoAssets = $foto !== '' ? APP_ROOT . '/public/assets/' . $foto : '';
    $arquivoPublico = $foto !== '' ? APP_ROOT . '/public/' . $foto : '';

    if ($arquivoAssets !== '' && is_file($arquivoAssets)) {
        return asset($foto);
    }

    return $arquivoPublico !== '' && is_file($arquivoPublico)
        ? url('/' . $foto)
        : asset('img/chacara-' . (($indice % 6) + 1) . '.jpg');
};

$galeria = [];
foreach ($fotos as $indice => $foto) {
    $galeria[] = $resolverFoto((string) $foto['caminho_foto'], $indice);
}
if ($galeria === []) {
    $galeria[] = $resolverFoto((string) ($chacara['foto_principal'] ?? ''), ((int) $chacara['id'] - 1));
}
$cidadeEstado = implode(' - ', array_filter([
    trim((string) ($chacara['cidade'] ?? '')),
    trim((string) ($chacara['estado'] ?? '')),
]));
$localizacao = implode(' · ', array_filter([$cidadeEstado, $chacara['regiao'] ?? null]));
$enderecoPublico = implode(' · ', array_filter([
    trim((string) ($chacara['endereco'] ?? '')),
    $cidadeEstado,
]));
$fotosExtras = max(0, count($galeria) - 5);
$temCoordenadas = (bool) ($coordenadasValidas ?? false);
$googleMapsConfigurado = (bool) ($googleMapsConfigurado ?? false);
$googleMapsEmbedUrl = (string) ($googleMapsEmbedUrl ?? '');
?>

<section class="profile-page">
    <div class="container">
        <nav class="profile-breadcrumb" aria-label="Navegação estrutural">
            <a href="<?= url('/') ?>">Início</a><span>›</span>
            <a href="<?= url('/#chacaras') ?>">Chácaras</a><span>›</span>
            <strong><?= e($chacara['nome']) ?></strong>
        </nav>

        <header class="profile-heading">
            <div>
                <span class="profile-kicker">Chácara disponível</span>
                <h1><?= e($chacara['nome']) ?></h1>
                <p><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><?= e($localizacao) ?></p>
            </div>
            <?php if ($notaMedia > 0): ?>
                <a class="profile-rating-summary" href="#avaliacoes">
                    <span>★</span><strong><?= e(number_format($notaMedia, 1, ',', '.')) ?></strong>
                    <small><?= count($avaliacoes) ?> <?= count($avaliacoes) === 1 ? 'avaliação' : 'avaliações' ?></small>
                </a>
            <?php endif; ?>
        </header>

        <div class="profile-gallery" data-gallery>
            <button class="profile-main-photo" type="button" data-gallery-open="0">
                <img src="<?= e($galeria[0]) ?>" alt="Foto principal de <?= e($chacara['nome']) ?>">
                <span>Foto principal</span>
            </button>
            <div class="profile-gallery-grid">
                <?php foreach (array_slice($galeria, 1, 4, true) as $indice => $fotoUrl): ?>
                    <button type="button" data-gallery-open="<?= (int) $indice ?>">
                        <img src="<?= e($fotoUrl) ?>" alt="Foto <?= (int) $indice + 1 ?> de <?= e($chacara['nome']) ?>">
                        <?php if ((int) $indice === 4 && $fotosExtras > 0): ?>
                            <span class="gallery-more-badge" aria-label="<?= e((string) $fotosExtras) ?> fotos adicionais">+<?= e((string) $fotosExtras) ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="profile-layout">
            <div class="profile-content">
                <section class="profile-section">
                    <span class="section-kicker">Sobre o espaço</span>
                    <h2>Um lugar para viver bons momentos</h2>
                    <p class="profile-description"><?= nl2br(e($chacara['descricao'] ?: 'Consulte as condições e os detalhes desta chácara.')) ?></p>
                    <div class="location-facts">
                        <div><span>Cidade</span><strong><?= e($cidadeEstado) ?></strong></div>
                        <div><span>Região</span><strong><?= e($chacara['regiao'] ?: 'Não informada') ?></strong></div>
                        <div><span>Endereço / referência</span><strong><?= e($chacara['endereco'] ?: 'Localização aproximada') ?></strong></div>
                    </div>
                    <?php if(!empty($chacara['checkin_hora_inicial'])):?><div class="location-facts"><div><span>Entrada</span><strong>das <?= e(substr($chacara['checkin_hora_inicial'],0,5)) ?> &agrave;s <?= e(substr($chacara['checkin_hora_final'],0,5)) ?></strong></div><div><span>Sa&iacute;da</span><strong>das <?= e(substr($chacara['checkout_hora_inicial'],0,5)) ?> &agrave;s <?= e(substr($chacara['checkout_hora_final'],0,5)) ?></strong></div></div><?php endif;?>
                </section>

                <section class="profile-section" id="disponibilidade">
                    <div class="section-heading-row">
                        <div><span class="section-kicker">Planeje sua estadia</span><h2>Calendário de disponibilidade</h2></div>
                        <div class="calendar-controls"><button type="button" data-calendar-prev aria-label="Mês anterior">‹</button><button type="button" data-calendar-next aria-label="Próximo mês">›</button></div>
                    </div>
                    <div class="calendar-shell">
                        <?php foreach ($calendarios as $indice => $calendario): ?>
                            <div class="availability-calendar" data-calendar-month="<?= $indice ?>" <?= $indice > 1 ? 'hidden' : '' ?>>
                                <h3><?= e($calendario['titulo']) ?></h3>
                                <div class="calendar-weekdays"><?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $dia): ?><span><?= $dia ?></span><?php endforeach; ?></div>
                                <div class="calendar-days">
                                    <?php for ($vazio = 0; $vazio < $calendario['espacos']; $vazio++): ?><span class="is-empty"></span><?php endfor; ?>
                                    <?php foreach ($calendario['dias'] as $dia): ?>
                                        <span class="<?= $dia['status'] !== 'disponivel' ? 'is-unavailable' : '' ?> <?= $dia['passado'] ? 'is-past' : '' ?>" title="<?= $dia['status'] === 'disponivel' ? 'Disponível' : 'Indisponível' ?>"><?= $dia['numero'] ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="calendar-legend"><span><i></i> Disponível</span><span><i></i> Indisponível</span></div>
                </section>

                <section class="profile-section" id="localizacao">
                    <span class="section-kicker">Como chegar</span>
                    <h2>Localização</h2>
                    <p class="map-address"><?= e($enderecoPublico !== '' ? $enderecoPublico : $localizacao) ?></p>
                    <?php if (!$temCoordenadas): ?>
                        <div class="map-unavailable">Localização não informada.</div>
                    <?php elseif (!$googleMapsConfigurado || $googleMapsEmbedUrl === ''): ?>
                        <div class="map-unavailable">Mapa indisponível no momento.</div>
                    <?php else: ?>
                        <iframe class="profile-map" title="Mapa de <?= e($chacara['nome']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= e($googleMapsEmbedUrl) ?>"></iframe>
                    <?php endif; ?>
                </section>

                <section class="profile-section reviews-section" id="avaliacoes">
                    <div class="section-heading-row">
                        <div><span class="section-kicker">Experiências reais</span><h2>Avaliações e comentários</h2></div>
                        <?php if ($notaMedia > 0): ?><div class="reviews-average"><span>★</span><strong><?= e(number_format($notaMedia, 1, ',', '.')) ?></strong><small>de 5</small></div><?php endif; ?>
                    </div>
                    <?php if ($reservaAvaliavel !== null): ?>
                        <button class="btn btn-review" type="button" data-review-open>Avaliar e Comentar</button>
                    <?php elseif (!isLoggedIn()): ?>
                        <a class="btn btn-review" href="<?= url('/login') ?>">Avaliar e Comentar</a>
                        <p class="review-rule">Entre para verificar se sua reserva já pode ser avaliada.</p>
                    <?php else: ?>
                        <button class="btn btn-review is-disabled" type="button" disabled>Avaliar e Comentar</button>
                        <p class="review-rule">Disponível após a finalização de uma reserva nesta chácara.</p>
                    <?php endif; ?>

                    <?php if ($avaliacoes === []): ?>
                        <div class="reviews-empty">Esta chácara ainda não recebeu avaliações.</div>
                    <?php else: ?>
                        <div class="reviews-list">
                            <?php foreach ($avaliacoes as $avaliacao): ?>
                                <article class="review-card">
                                    <div class="review-avatar"><?= e(mb_strtoupper(mb_substr($avaliacao['usuario_nome'], 0, 1))) ?></div>
                                    <div>
                                        <header><strong><?= e($avaliacao['usuario_nome']) ?></strong><time datetime="<?= e($avaliacao['data_avaliacao']) ?>"><?= e(date('d/m/Y', strtotime($avaliacao['data_avaliacao']))) ?></time></header>
                                        <div class="review-stars" aria-label="<?= e($avaliacao['nota']) ?> de 5 estrelas"><?= str_repeat('★', (int) $avaliacao['nota']) ?><span><?= str_repeat('★', 5 - (int) $avaliacao['nota']) ?></span></div>
                                        <p><?= e($avaliacao['comentario'] ?: 'O cliente não deixou um comentário.') ?></p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="booking-card">
                <span>A partir de</span>
                <div class="booking-price"><strong>R$ <?= e(number_format((float) $chacara['valor_diaria'], 2, ',', '.')) ?></strong><small>/ diária</small></div>
                <p>Consulte o calendário e escolha as melhores datas para sua estadia.</p>
                <a class="btn btn-book" href="<?= url('/reserva/criar/' . (int) $chacara['id']) ?>">Reservar</a>
                <small class="booking-login-note"><?= isLoggedIn() ? 'Você será direcionado para concluir a reserva.' : 'É necessário entrar para reservar.' ?></small>
            </aside>
        </div>
    </div>
</section>

<div class="gallery-modal" data-gallery-modal hidden>
    <button type="button" class="gallery-close" data-gallery-close aria-label="Fechar galeria">×</button>
    <button type="button" class="gallery-nav gallery-prev" data-gallery-prev aria-label="Foto anterior">‹</button>
    <img src="" alt="Foto ampliada da chácara" data-gallery-modal-image>
    <button type="button" class="gallery-nav gallery-next" data-gallery-next aria-label="Próxima foto">›</button>
</div>

<?php if ($reservaAvaliavel !== null): ?>
    <div class="review-modal" data-review-modal hidden>
        <div class="review-modal-card">
            <button type="button" class="review-modal-close" data-review-close aria-label="Fechar">×</button>
            <span class="section-kicker">Conte sua experiência</span>
            <h2>Avaliar e comentar</h2>
            <form action="<?= url('/chacara/' . (int) $chacara['id'] . '/avaliar') ?>" method="post">
                <?= csrf_field() ?>
                <fieldset class="star-input">
                    <legend>Sua nota</legend>
                    <?php for ($nota = 5; $nota >= 1; $nota--): ?><input id="nota-<?= $nota ?>" type="radio" name="nota" value="<?= $nota ?>" required><label for="nota-<?= $nota ?>" title="<?= $nota ?> estrelas">★</label><?php endfor; ?>
                </fieldset>
                <label for="comentario">Comentário</label>
                <textarea id="comentario" name="comentario" minlength="3" maxlength="1500" rows="5" required placeholder="O que você mais gostou?"></textarea>
                <button class="btn btn-primary" type="submit">Publicar avaliação</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<script type="application/json" data-gallery-images><?= json_encode($galeria, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
