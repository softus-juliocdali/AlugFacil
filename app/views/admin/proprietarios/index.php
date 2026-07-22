<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (?string $data): string => $data ? date('d/m/Y', strtotime($data)) : '-';
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1>Propriet&aacute;rios</h1>
        <p>Gerencie acessos, status e ch&aacute;caras vinculadas aos propriet&aacute;rios.</p>
    </div>
</div>

<section class="panel-card admin-filter-card">
    <form class="admin-search-form" method="get" action="<?= url('/admin/proprietarios') ?>">
        <label>
            Buscar por nome, telefone ou e-mail
            <input type="search" name="busca" value="<?= e($busca) ?>" placeholder="Digite para buscar">
        </label>
        <button class="btn btn-primary" type="submit">Buscar</button>
        <?php if ($busca !== ''): ?>
            <a class="btn btn-outline" href="<?= url('/admin/proprietarios') ?>">Limpar</a>
        <?php endif; ?>
    </form>
</section>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Rela&ccedil;&atilde;o de propriet&aacute;rios</h2>
    </div>

    <?php if (empty($proprietarios)): ?>
        <div class="empty-preview">
            <span>&#9633;</span>
            <div>
                <strong>Nenhum propriet&aacute;rio encontrado</strong>
                <p>Revise a busca ou aguarde novos cadastros.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="responsive-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>E-mail</th>
                        <th>Ch&aacute;caras</th>
                        <th>Status</th>
                        <th>Cadastro</th>
                        <th>A&ccedil;&otilde;es</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proprietarios as $proprietario): ?>
                        <?php $novoStatus = $proprietario['status'] === 'bloqueado' ? 'ativo' : 'bloqueado'; ?>
                        <tr>
                            <td><?= e($proprietario['nome']) ?></td>
                            <td><?= e($proprietario['telefone'] ?: '-') ?></td>
                            <td><?= e($proprietario['email']) ?></td>
                            <td><?= e((string) (int) $proprietario['total_chacaras']) ?></td>
                            <td><span class="status-pill"><?= e($formatarStatus($proprietario['status'])) ?></span></td>
                            <td><?= e($formatarData($proprietario['data_cadastro'])) ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a class="btn btn-outline btn-small" href="<?= url('/admin/proprietarios/' . (int) $proprietario['id']) ?>">Detalhes</a>
                                    <form method="post" action="<?= url('/admin/proprietarios/' . (int) $proprietario['id'] . '/status') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="<?= e($novoStatus) ?>">
                                        <button class="btn <?= $novoStatus === 'bloqueado' ? 'btn-danger' : 'btn-outline' ?> btn-small" type="submit">
                                            <?= $novoStatus === 'bloqueado' ? 'Bloquear' : 'Ativar' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
