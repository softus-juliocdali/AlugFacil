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
                                <form class="inline-status-form" method="post" action="<?= url('/proprietario/chacaras/editar/' . (int) $chacara['id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="nome" value="<?= e($chacara['nome']) ?>">
                                    <input type="hidden" name="descricao" value="<?= e($chacara['descricao'] ?? '') ?>">
                                    <input type="hidden" name="tipo_imovel" value="<?= e($chacara['tipo_imovel'] ?? 'chacara') ?>">
                                    <input type="hidden" name="valor_diaria" value="<?= e((string) $chacara['valor_diaria']) ?>">
                                    <input type="hidden" name="cidade" value="<?= e($chacara['cidade']) ?>">
                                    <input type="hidden" name="regiao" value="<?= e($chacara['regiao'] ?? '') ?>">
                                    <input type="hidden" name="endereco" value="<?= e($chacara['endereco'] ?? '') ?>">
                                    <input type="hidden" name="latitude" value="<?= e((string) ($chacara['latitude'] ?? '')) ?>">
                                    <input type="hidden" name="longitude" value="<?= e((string) ($chacara['longitude'] ?? '')) ?>">
                                    <select name="status" onchange="this.form.submit()" aria-label="Alterar status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $chacara['status'] === $status ? 'selected' : '' ?>><?= e($formatarStatus($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
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
