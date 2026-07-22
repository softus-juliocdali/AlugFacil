<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
?>
<div class="panel-page-heading">
    <div>
        <span>Gestao de chacaras</span>
        <h1>Minhas ch&aacute;caras</h1>
        <p>Cadastre, edite, desative e organize as fotos dos seus im&oacute;veis.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/proprietario/chacaras/criar') ?>">Cadastrar ch&aacute;cara</a>
</div>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Ch&aacute;caras cadastradas</h2>
    </div>

    <?php if (empty($chacaras)): ?>
        <div class="empty-preview">
            <span>&#9636;</span>
            <div>
                <strong>Nenhuma ch&aacute;cara cadastrada</strong>
                <p>Comece criando seu primeiro cadastro e depois envie as fotos.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="responsive-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Ch&aacute;cara</th>
                        <th>Cidade</th>
                        <th>Di&aacute;ria</th>
                        <th>Status</th>
                        <th>Fotos</th>
                        <th>A&ccedil;&otilde;es</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chacaras as $chacara): ?>
                        <tr>
                            <td>
                                <div class="owner-property-cell">
                                    <img src="<?= e(asset($chacara['foto_principal'] ?: 'img/chacara-1.jpg')) ?>" alt="">
                                    <div>
                                        <strong><?= e($chacara['nome']) ?></strong>
                                        <small><?= e($chacara['regiao'] ?: 'Regiao nao informada') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($chacara['cidade']) ?></td>
                            <td>R$ <?= e(number_format((float) $chacara['valor_diaria'], 2, ',', '.')) ?></td>
                            <td>
                                <?php if ($chacara['status_aprovacao'] === 'aprovada'): ?>
                                <form class="inline-status-form" method="post" action="<?= url('/proprietario/chacaras/status/' . (int) $chacara['id']) ?>">
                                    <?= csrf_field() ?>
                                    <select name="status" onchange="this.form.submit()" aria-label="Alterar status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $chacara['status_operacional'] === $status ? 'selected' : '' ?>><?= e($formatarStatus($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php else: ?>
                                    <span class="status-pill"><?= e($formatarStatus($chacara['status_aprovacao'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) (int) $chacara['total_fotos']) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="btn btn-outline btn-small" href="<?= url('/proprietario/chacaras/editar/' . (int) $chacara['id']) ?>">Editar</a>
                                    <a class="btn btn-outline btn-small" href="<?= url('/proprietario/chacaras/fotos/' . (int) $chacara['id']) ?>">Fotos</a>
                                    <a class="btn btn-danger btn-small" href="<?= url('/proprietario/chacaras/excluir/' . (int) $chacara['id']) ?>">Desativar</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
