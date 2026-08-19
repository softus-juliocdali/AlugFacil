<div class="panel-page-heading">
    <div>
        <span>Fotos da chacara</span>
        <h1><?= e($chacara['nome']) ?></h1>
        <p>Envie imagens, escolha a foto principal e remova fotos antigas.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/proprietario/chacaras') ?>">Voltar</a>
</div>

<section class="panel-card upload-card">
    <div class="panel-card-heading">
        <h2>Enviar fotos</h2>
    </div>
    <form class="owner-upload-form" method="post" action="<?= url('/proprietario/chacaras/fotos/' . (int) $chacara['id']) ?>" enctype="multipart/form-data" data-upload-form data-max-file-bytes="<?= (int) $maxUploadBytes ?>" data-max-post-bytes="<?= (int) $postMaxBytes ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="upload">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $maxUploadBytes ?>">
        <label class="upload-drop">
            <span>&#8682;</span>
            <strong>Selecionar imagens</strong>
            <small>JPG, JPEG, PNG ou WEBP ate <?= e($maxUploadLabel) ?> por arquivo. Lote maximo do servidor: <?= e($postMaxLabel) ?>.</small>
            <input type="file" name="fotos[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple data-photo-input>
        </label>
        <div class="upload-feedback" data-photo-feedback hidden></div>
        <div class="photo-preview-grid" data-photo-preview></div>
        <button class="btn btn-primary" type="submit">Enviar fotos</button>
    </form>
</section>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Fotos cadastradas</h2>
    </div>

    <?php if (empty($fotos)): ?>
        <div class="empty-preview">
            <span>&#9636;</span>
            <div>
                <strong>Nenhuma foto enviada</strong>
                <p>Envie fotos reais da ch&aacute;cara para melhorar o an&uacute;ncio.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="photo-grid">
            <?php foreach ($fotos as $foto): ?>
                <article class="photo-card">
                    <img src="<?= e(asset($foto['caminho_foto'])) ?>" alt="">
                    <?php if ($foto['principal'] === 'sim'): ?>
                        <span class="main-photo-badge">Principal</span>
                    <?php endif; ?>
                    <div>
                        <?php if ($foto['principal'] !== 'sim'): ?>
                            <form method="post" action="<?= url('/proprietario/chacaras/fotos/' . (int) $chacara['id']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="principal">
                                <input type="hidden" name="foto_id" value="<?= (int) $foto['id'] ?>">
                                <button class="btn btn-outline btn-small" type="submit">Definir principal</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/proprietario/chacaras/fotos/' . (int) $chacara['id']) ?>" onsubmit="return confirm('Remover esta foto?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="foto_id" value="<?= (int) $foto['id'] ?>">
                            <button class="btn btn-danger btn-small" type="submit">Remover</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
