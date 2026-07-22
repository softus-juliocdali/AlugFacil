<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (?string $data): string => $data ? date('d/m/Y', strtotime($data)) : '-';
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1>Administradores</h1>
        <p>Cadastre, edite e controle os acessos administrativos do sistema.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/admin/administradores/criar') ?>">Novo administrador</a>
</div>

<section class="panel-card admin-filter-card">
    <form class="admin-search-form" method="get" action="<?= url('/admin/administradores') ?>">
        <label>
            Buscar por nome, telefone ou e-mail
            <input type="search" name="busca" value="<?= e($busca) ?>" placeholder="Digite para buscar">
        </label>
        <button class="btn btn-primary" type="submit">Buscar</button>
        <?php if ($busca !== ''): ?>
            <a class="btn btn-outline" href="<?= url('/admin/administradores') ?>">Limpar</a>
        <?php endif; ?>
    </form>
</section>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Contas administrativas</h2>
    </div>

    <?php if (empty($administradores)): ?>
        <div class="empty-preview">
            <span>&#9633;</span>
            <div>
                <strong>Nenhum administrador encontrado</strong>
                <p>Cadastre uma nova conta administrativa.</p>
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
                        <th>Status</th>
                        <th>Cadastro</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($administradores as $admin): ?>
                        <tr>
                            <td><?= e($admin['nome']) ?></td>
                            <td><?= e($admin['telefone'] ?: '-') ?></td>
                            <td><?= e($admin['email']) ?></td>
                            <td><span class="status-pill"><?= e($formatarStatus($admin['status'])) ?></span></td>
                            <td><?= e($formatarData($admin['data_cadastro'])) ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a class="btn btn-outline btn-small" href="<?= url('/admin/administradores/' . (int) $admin['id'] . '/editar') ?>">Editar</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
