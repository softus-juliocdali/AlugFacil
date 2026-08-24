<?php $formatDate = static fn (?string $date): string => $date ? date('d/m/Y', strtotime($date)) : '-'; $money=static fn(int$value):string=>'R$ '.number_format($value/100,2,',','.'); ?>
<header class="panel-page-heading">
    <div><span>Administração</span><h1>Afiliados</h1><p>Cadastre e controle os acessos ao programa de afiliados.</p></div>
    <div class="heading-actions">
        <a class="btn btn-outline" href="<?= url('/admin/afiliados/configuracao') ?>">Configurar comissão</a>
        <a class="btn btn-primary" href="<?= url('/admin/afiliados/criar') ?>">Novo afiliado</a>
    </div>
</header>

<section class="panel-card admin-filter-card">
    <form class="affiliate-filter-form" method="get" action="<?= url('/admin/afiliados') ?>">
        <label>Nome<input type="search" name="nome" value="<?= e($filters['nome']) ?>"></label>
        <label>Código<input type="search" name="codigo" value="<?= e($filters['codigo']) ?>" placeholder="AF0001"></label>
        <label>CPF/CNPJ<input type="search" name="cpf_cnpj" value="<?= e($filters['cpf_cnpj']) ?>"></label>
        <label>E-mail<input type="search" name="email" value="<?= e($filters['email']) ?>"></label>
        <label>Status<select name="status"><option value="">Todos</option><option value="ativo" <?= $filters['status']==='ativo'?'selected':'' ?>>Ativo</option><option value="bloqueado" <?= $filters['status']==='bloqueado'?'selected':'' ?>>Bloqueado</option></select></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn btn-outline" href="<?= url('/admin/afiliados') ?>">Limpar</a></div>
    </form>
</section>

<section class="panel-card">
<div class="panel-card-heading"><h2>Relação de afiliados</h2></div>
<?php if ($affiliates === []): ?>
    <div class="empty-preview"><span>&#9733;</span><div><strong>Nenhum afiliado encontrado</strong><p>Ajuste os filtros ou cadastre o primeiro afiliado.</p></div></div>
<?php else: ?>
    <div class="responsive-table"><table class="panel-table affiliate-admin-table"><thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">CPF/CNPJ</th><th scope="col">E-mail</th><th scope="col">Status</th><th scope="col">Em aberto</th><th scope="col">Saldo disponível</th><th scope="col">Total pago</th><th scope="col">Ações</th></tr></thead><tbody>
    <?php foreach ($affiliates as $affiliate): ?><tr>
        <td><strong><?= e($affiliate['codigo']) ?></strong><small><?= e($formatDate($affiliate['criado_em'])) ?></small></td><td><strong><?= e($affiliate['nome']) ?></strong></td><td><?= e($affiliate['cpf_cnpj']) ?></td><td><?= e($affiliate['email']) ?></td>
        <td><span class="status-pill affiliate-status-<?= e($affiliate['status']) ?>"><?= e(ucfirst($affiliate['status'])) ?></span></td><td><?= e($money((int)$affiliate['financeiro']['em_aberto_centavos'])) ?></td><td><strong><?= e($money((int)$affiliate['financeiro']['saldo_disponivel_centavos'])) ?></strong></td><td><?= e($money((int)$affiliate['financeiro']['total_pago_centavos'])) ?></td>
        <td><div class="table-actions"><a class="btn btn-outline btn-small" href="<?= url('/admin/afiliados/'.$affiliate['id'].'/financeiro') ?>">Financeiro</a><a class="btn btn-outline btn-small" href="<?= url('/admin/afiliados/'.$affiliate['id'].'/editar') ?>">Editar</a>
            <form method="post" action="<?= url('/admin/afiliados/'.$affiliate['id'].'/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $affiliate['status']==='ativo'?'bloqueado':'ativo' ?>"><button class="btn btn-small <?= $affiliate['status']==='ativo'?'btn-danger':'btn-primary' ?>" type="submit"><?= $affiliate['status']==='ativo'?'Bloquear':'Reativar' ?></button></form>
        </div></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</section>
