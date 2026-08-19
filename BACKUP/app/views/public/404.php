<section class="simple-page">
    <div class="container">
        <span class="error-code">404</span>
        <h1>Pagina nao encontrada</h1>
        <p><?= e($message ?? 'O endereco pode ter mudado ou nao esta disponivel.') ?></p>
        <a class="btn btn-primary" href="<?= url('/') ?>">Voltar para o inicio</a>
    </div>
</section>
