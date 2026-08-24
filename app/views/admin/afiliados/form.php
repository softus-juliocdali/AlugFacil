<?php
$item = $affiliate ?? [];
$editing = $mode === 'editar';
$value = static fn (string $field, string $default = ''): string => old($field, (string) ($item[$field] ?? $default));
$pixLabels = ['cpf'=>'CPF','cnpj'=>'CNPJ','email'=>'E-mail','telefone'=>'Telefone','aleatoria'=>'Chave aleatória'];
?>
<header class="panel-page-heading"><div><span>Administração</span><h1><?= $editing?'Editar afiliado':'Novo afiliado' ?></h1><p>Dados cadastrais, financeiros e de acesso.</p></div><a class="btn btn-outline" href="<?= url('/admin/afiliados') ?>">Voltar</a></header>
<section class="panel-card owner-form-card affiliate-form-card">
    <form class="owner-property-form affiliate-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <fieldset class="affiliate-form-section form-full">
            <legend>Dados do afiliado</legend>
            <div class="affiliate-form-grid">
                <?php if ($editing): ?>
                    <label>Código<input type="text" value="<?= e($item['codigo']) ?>" readonly aria-readonly="true"></label>
                <?php endif; ?>
                <label>Nome<input name="nome" value="<?= e($value('nome')) ?>" maxlength="150" autocomplete="name" required></label>
                <label>CPF ou CNPJ<input name="cpf_cnpj" value="<?= e($value('cpf_cnpj')) ?>" maxlength="18" inputmode="numeric" required></label>
                <label>E-mail<input type="email" name="email" value="<?= e($value('email')) ?>" maxlength="180" autocomplete="email" required></label>
                <label>Telefone<input type="tel" name="telefone" value="<?= e($value('telefone')) ?>" maxlength="30" autocomplete="tel" required></label>
            </div>
        </fieldset>

        <fieldset class="affiliate-form-section form-full">
            <legend>Acesso</legend>
            <div class="affiliate-form-grid">
                <label>Senha<input type="password" name="senha" autocomplete="new-password" <?= $editing?'placeholder="Deixe em branco para manter"':'required' ?>></label>
                <label>Confirmar senha<input type="password" name="senha_confirmacao" autocomplete="new-password" <?= $editing?'':'required' ?>></label>
                <label>Status<select name="status" required><option value="ativo" <?= $value('status','ativo')==='ativo'?'selected':'' ?>>Ativo</option><option value="bloqueado" <?= $value('status')==='bloqueado'?'selected':'' ?>>Bloqueado</option></select></label>
            </div>
        </fieldset>

        <fieldset class="affiliate-form-section form-full">
            <legend>Dados para pagamento</legend>
            <div class="affiliate-form-grid">
                <label>Tipo da chave PIX<select name="tipo_chave_pix" required><option value="">Selecione</option><?php foreach ($pixTypes as $type): ?><option value="<?= e($type) ?>" <?= $value('tipo_chave_pix')===$type?'selected':'' ?>><?= e($pixLabels[$type]) ?></option><?php endforeach; ?></select></label>
                <label>Chave PIX<input name="chave_pix" value="<?= e($value('chave_pix')) ?>" maxlength="150" required></label>
                <label>Banco (opcional)<input name="banco" value="<?= e($value('banco')) ?>" maxlength="120" autocomplete="organization"></label>
            </div>
        </fieldset>

        <fieldset class="affiliate-form-section form-full">
            <legend>Informações adicionais</legend>
            <div class="affiliate-form-grid">
                <label class="form-full">Observações<textarea name="observacoes" maxlength="2000" rows="6"><?= e($value('observacoes')) ?></textarea></label>
            </div>
        </fieldset>

        <div class="form-actions affiliate-form-actions">
            <button class="btn btn-primary" type="submit">Salvar afiliado</button>
            <a class="btn btn-outline" href="<?= url('/admin/afiliados') ?>">Cancelar</a>
        </div>
    </form>
</section>
