<?php $label = static fn (string $valor): string => ucfirst(str_replace('_', ' ', $valor)); ?>
<div class="panel-page-heading"><div><span>Administracao</span><h1>Im&oacute;veis</h1><p>Aprova&ccedil;&atilde;o e bloqueio dos im&oacute;veis cadastrados.</p></div></div>
<section class="panel-card">
    <form class="panel-filters" method="get" action="<?= url('/admin/chacaras') ?>">
        <label>Status <select name="status"><option value="">Todos</option><?php foreach (['pendente','aprovada','rejeitada','bloqueada'] as $opcao): ?><option value="<?= e($opcao) ?>" <?= $status === $opcao ? 'selected' : '' ?>><?= e($label($opcao)) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
    <div class="responsive-table"><table class="panel-table"><thead><tr><th>Im&oacute;vel</th><th>Propriet&aacute;rio</th><th>Cidade</th><th>Aprova&ccedil;&atilde;o</th><th>Disponibilidade</th><th></th></tr></thead><tbody>
    <?php foreach ($chacaras as $chacara): ?><tr><td><?= e($chacara['nome']) ?></td><td><?= e($chacara['proprietario_nome']) ?></td><td><?= e($chacara['cidade']) ?></td><td><span class="status-pill"><?= e($label($chacara['status_aprovacao'])) ?></span></td><td><?= e($label($chacara['status_operacional'])) ?></td><td><a class="btn btn-outline" href="<?= url('/admin/chacaras/' . (int) $chacara['id']) ?>">Detalhes</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
