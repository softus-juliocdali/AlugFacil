<div class="panel-page-heading">
    <div>
        <span>Desativar chacara</span>
        <h1><?= e($chacara['nome']) ?></h1>
        <p>A ch&aacute;cara sair&aacute; da listagem p&uacute;blica, mas reservas e hist&oacute;rico ser&atilde;o preservados.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/proprietario/chacaras') ?>">Voltar</a>
</div>

<section class="panel-card owner-form-card">
    <div class="empty-preview">
        <span>&#9888;</span>
        <div>
            <strong>Confirmar desativa&ccedil;&atilde;o</strong>
            <p>Use esta a&ccedil;&atilde;o quando n&atilde;o quiser receber novas reservas para esta ch&aacute;cara.</p>
        </div>
    </div>

    <form class="delete-confirm-form" method="post" action="<?= url('/proprietario/chacaras/excluir/' . (int) $chacara['id']) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-danger" type="submit">Desativar ch&aacute;cara</button>
        <a class="btn btn-outline" href="<?= url('/proprietario/chacaras') ?>">Cancelar</a>
    </form>
</section>
