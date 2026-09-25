<?php
$formatarStatus = static fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
$formatarData = static fn (?string $data): string => $data ? date('d/m/Y', strtotime($data)) : '-';
$formatarMoeda = static fn (float $valor): string => 'R$ ' . number_format($valor, 2, ',', '.');
$formatarCpf = static function (?string $cpf): string {
    $digitos = preg_replace('/\D+/', '', (string) $cpf);
    if (strlen($digitos) === 14) {
        return substr($digitos,0,2).'.'.substr($digitos,2,3).'.'.substr($digitos,5,3).'/'.substr($digitos,8,4).'-'.substr($digitos,12,2);
    }
    if (strlen($digitos) !== 11) {
        return '-';
    }
    return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-' . substr($digitos, 9, 2);
};
$transicoes = [
    'pendente' => ['ativo' => 'Aprovar', 'rejeitado' => 'Rejeitar'],
    'ativo' => ['bloqueado' => 'Bloquear'],
    'bloqueado' => ['ativo' => 'Reativar'],
    'rejeitado' => ['pendente' => 'Reabrir analise'],
];
?>
<div class="panel-page-heading">
    <div>
        <span>Administracao</span>
        <h1><?= e($proprietario['nome']) ?></h1>
        <p>Dados do propriet&aacute;rio e ch&aacute;caras vinculadas.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/admin/proprietarios') ?>">Voltar</a>
</div>

<section class="admin-detail-grid">
    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Dados b&aacute;sicos</h2>
        </div>
        <dl class="admin-detail-list">
            <div><dt>Nome</dt><dd><?= e($proprietario['nome']) ?></dd></div>
            <div><dt>Telefone</dt><dd><?= e($proprietario['telefone'] ?: '-') ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($proprietario['email']) ?></dd></div>
            <div><dt>CPF/CNPJ</dt><dd><?= e($formatarCpf($proprietario['cpf_cnpj'] ?? null)) ?></dd></div>
            <div><dt>Situação cadastral</dt><dd><?= e($proprietario['situacao_cadastro'] ?? 'incompleto') ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-pill"><?= e($formatarStatus($proprietario['status'])) ?></span></dd></div>
            <div><dt>Status de login</dt><dd><span class="status-pill"><?= e($formatarStatus($proprietario['usuario_status'])) ?></span></dd></div>
            <div><dt>Cadastro</dt><dd><?= e($formatarData($proprietario['data_cadastro'])) ?></dd></div>
            <div><dt>Atualizacao</dt><dd><?= e($formatarData($proprietario['data_atualizacao'])) ?></dd></div>
            <div><dt>Chacaras cadastradas</dt><dd><?= e((string) (int) $proprietario['total_chacaras']) ?></dd></div>
        </dl>
        <form class="admin-status-action owner-property-form" method="post" action="<?= url('/admin/proprietarios/' . (int) $proprietario['id'] . '/status') ?>">
            <?= csrf_field() ?>
            <label class="form-full">Motivo <input type="text" name="motivo" value="<?= e($proprietario['motivo_status'] ?? '') ?>"></label>
            <div class="form-actions">
                <?php foreach ($transicoes[$proprietario['status']] ?? [] as $status => $rotulo): ?>
                    <button class="btn <?= in_array($status, ['bloqueado', 'rejeitado'], true) ? 'btn-danger' : 'btn-primary' ?>" type="submit" name="status" value="<?= e($status) ?>"><?= e($rotulo) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </article>

    <article class="panel-card">
        <div class="panel-card-heading">
            <h2>Resumo</h2>
        </div>
        <dl class="admin-detail-list">
            <div><dt>Ch&aacute;caras vinculadas</dt><dd><?= e((string) (int) $proprietario['total_chacaras']) ?></dd></div>
            <div><dt>Acesso interno</dt><dd><?= e($proprietario['usuario_status'] === 'ativo' ? 'Liberado' : 'Bloqueado') ?></dd></div>
        </dl>
    </article>
</section>

<section class="panel-card">
    <div class="panel-card-heading">
        <h2>Ch&aacute;caras vinculadas</h2>
    </div>

    <?php if (empty($chacaras)): ?>
        <div class="empty-preview">
            <span>&#9633;</span>
            <div>
                <strong>Nenhuma ch&aacute;cara vinculada</strong>
                <p>Este propriet&aacute;rio ainda n&atilde;o cadastrou im&oacute;veis.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="responsive-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Ch&aacute;cara</th>
                        <th>Cidade</th>
                        <th>Valor</th>
                        <th>Reservas</th>
                        <th>Status</th>
                        <th>Cadastro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chacaras as $chacara): ?>
                        <tr>
                            <td><?= e($chacara['nome']) ?></td>
                            <td><?= e(implode(' - ', array_filter([$chacara['cidade'], $chacara['regiao']]))) ?></td>
                            <td><?= e($formatarMoeda((float) $chacara['valor_diaria'])) ?></td>
                            <td><?= e((string) (int) $chacara['total_reservas']) ?></td>
                            <td><span class="status-pill"><?= e($formatarStatus($chacara['status'])) ?></span></td>
                            <td><?= e($formatarData($chacara['data_cadastro'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
