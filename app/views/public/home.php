<section class="hero">
    <div class="hero-backdrop"></div>
    <div class="container hero-content">
        <div class="hero-copy">
            <h1>Encontre a chácara perfeita para relaxar com quem você ama</h1>
            <p>São centenas de opções para finais de semana, feriados e momentos inesquecíveis.</p>

            <div class="trust-list" aria-label="Vantagens">
                <div class="trust-item"><span class="trust-icon">✓</span><span><strong>Reserva fácil</strong> e segura</span></div>
                <div class="trust-item"><span class="trust-icon">▣</span><span><strong>Pagamento</strong> 100% seguro</span></div>
                <div class="trust-item"><span class="trust-icon">◉</span><span><strong>Atendimento</strong> especializado</span></div>
            </div>
        </div>

        <form class="search-box" action="<?= url('/') ?>" method="get">
            <label class="search-field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                <span><strong>Destino</strong><input type="text" name="cidade" value="<?= e($filtros['cidade']) ?>" placeholder="Para onde você quer ir?"></span>
            </label>
            <label class="search-field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                <span><strong>Check-in</strong><input type="date" name="data_inicio" value="<?= e($filtros['data_inicio']) ?>" min="<?= e(date('Y-m-d')) ?>"></span>
            </label>
            <label class="search-field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                <span><strong>Check-out</strong><input type="date" name="data_fim" value="<?= e($filtros['data_fim']) ?>" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></span>
            </label>
            <button class="btn btn-search" type="submit">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                Buscar chácaras
            </button>
        </form>
    </div>
</section>

<section class="listings-section" id="chacaras">
    <div class="container listings-layout">
        <aside class="filters" data-filters>
            <form action="<?= url('/') ?>#chacaras" method="get">
                <div class="filters-heading">
                    <h2>Filtros</h2>
                    <a href="<?= url('/#chacaras') ?>">Limpar</a>
                </div>

                <div class="filter-group">
                    <label for="cidade">Cidade</label>
                    <input id="cidade" name="cidade" type="text" value="<?= e($filtros['cidade']) ?>" placeholder="Ex.: Ibiúna">
                </div>

                <div class="filter-group">
                    <label for="regiao">Região</label>
                    <input id="regiao" name="regiao" type="text" value="<?= e($filtros['regiao']) ?>" placeholder="Ex.: Rota do Vinho">
                </div>

                <div class="filter-group">
                    <label for="tipo_imovel">Tipo de im&oacute;vel</label>
                    <select id="tipo_imovel" name="tipo_imovel">
                        <option value="" <?= ($filtros['tipo_imovel'] ?? '') === '' ? 'selected' : '' ?>>Todos</option>
                        <option value="chacara" <?= ($filtros['tipo_imovel'] ?? '') === 'chacara' ? 'selected' : '' ?>>Ch&aacute;cara</option>
                        <option value="sitio" <?= ($filtros['tipo_imovel'] ?? '') === 'sitio' ? 'selected' : '' ?>>S&iacute;tio</option>
                        <option value="area_lazer" <?= ($filtros['tipo_imovel'] ?? '') === 'area_lazer' ? 'selected' : '' ?>>&Aacute;rea de lazer</option>
                    </select>
                </div>

                <fieldset class="filter-group">
                    <legend>Valor da diária</legend>
                    <div class="filter-price-grid">
                        <label>De<input name="valor_min" type="number" min="0" step="0.01" value="<?= e($filtros['valor_min'] ?? '') ?>" placeholder="R$ 0"></label>
                        <label>Até<input name="valor_max" type="number" min="0" step="0.01" value="<?= e($filtros['valor_max'] ?? '') ?>" placeholder="R$ 3.000"></label>
                    </div>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Disponibilidade</legend>
                    <label>Entrada<input name="data_inicio" type="date" value="<?= e($filtros['data_inicio']) ?>" min="<?= e(date('Y-m-d')) ?>"></label>
                    <label>Saída<input name="data_fim" type="date" value="<?= e($filtros['data_fim']) ?>" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></label>
                </fieldset>

                <input type="hidden" name="ordenacao" value="<?= e($filtros['ordenacao']) ?>">
                <button class="btn btn-filter" type="submit">Aplicar filtros</button>
            </form>
        </aside>

        <div class="listings-content">
            <div class="listings-toolbar">
                <div>
                    <button class="filter-mobile-button" type="button" data-filter-toggle>Filtros</button>
                    <h2>Chácaras disponíveis</h2>
                    <p><?= count($properties) ?> <?= count($properties) === 1 ? 'opção encontrada' : 'opções encontradas' ?></p>
                </div>
                <form class="sort-control" action="<?= url('/') ?>#chacaras" method="get">
                    <?php foreach (['cidade', 'regiao', 'tipo_imovel', 'valor_min', 'valor_max', 'data_inicio', 'data_fim'] as $campo): ?>
                        <input type="hidden" name="<?= e($campo) ?>" value="<?= e($filtros[$campo] ?? '') ?>">
                    <?php endforeach; ?>
                    <label for="ordenacao">Ordenar por:</label>
                    <select id="ordenacao" name="ordenacao" data-auto-submit>
                        <option value="relevancia" <?= $filtros['ordenacao'] === 'relevancia' ? 'selected' : '' ?>>Relevância</option>
                        <option value="menor_preco" <?= $filtros['ordenacao'] === 'menor_preco' ? 'selected' : '' ?>>Menor preço</option>
                        <option value="maior_preco" <?= $filtros['ordenacao'] === 'maior_preco' ? 'selected' : '' ?>>Maior preço</option>
                        <option value="nome" <?= $filtros['ordenacao'] === 'nome' ? 'selected' : '' ?>>Nome</option>
                    </select>
                </form>
            </div>

            <?php require APP_ROOT . '/app/views/public/lista_chacaras.php'; ?>
        </div>
    </div>
</section>

<section class="steps-section" id="como-funciona">
    <div class="container">
        <span class="section-kicker">Simples e seguro</span>
        <h2>Seu descanso em três passos</h2>
        <div class="steps-grid">
            <article><span>01</span><h3>Encontre</h3><p>Use a busca e os filtros para descobrir o lugar ideal.</p></article>
            <article><span>02</span><h3>Reserve</h3><p>Confira os detalhes, escolha as datas e faça sua reserva.</p></article>
            <article><span>03</span><h3>Aproveite</h3><p>Reúna quem você ama e viva momentos inesquecíveis.</p></article>
        </div>
    </div>
</section>

<section class="owner-cta" id="anuncie">
    <div class="container owner-cta-inner">
        <div><span>Você é proprietário?</span><h2>Transforme sua chácara em novas oportunidades</h2><p>Anuncie para pessoas que procuram o lugar perfeito para descansar.</p></div>
        <a class="btn btn-light" href="<?= url('/cadastro-proprietario') ?>">Quero anunciar</a>
    </div>
</section>
