<?php
$formatarData = static fn (string $data): string => date('d/m/Y', strtotime($data));
$statusLabel = static fn (string $status): string => match ($status) {
    'reservado' => 'Reservado',
    'bloqueado' => 'Bloqueado',
    default => 'Disponivel',
};
?>
<div class="panel-page-heading">
    <div>
        <span>Calendario do proprietario</span>
        <h1>Disponibilidade</h1>
        <p>Visualize reservas confirmadas, bloqueie datas e libere bloqueios manuais.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('/proprietario/chacaras') ?>">Minhas ch&aacute;caras</a>
</div>

<?php if (empty($chacaras)): ?>
    <section class="panel-card">
        <div class="empty-preview">
            <span>&#9638;</span>
            <div>
                <strong>Nenhuma ch&aacute;cara cadastrada</strong>
                <p>Cadastre uma ch&aacute;cara antes de gerenciar disponibilidade.</p>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="panel-card availability-toolbar">
        <form method="get" action="<?= url('/proprietario/disponibilidade/' . (int) ($chacara['id'] ?? $chacaras[0]['id'])) ?>">
            <label>
                Ch&aacute;cara
                <select data-availability-property>
                    <?php foreach ($chacaras as $item): ?>
                        <option value="<?= url('/proprietario/disponibilidade/' . (int) $item['id']) ?>" <?= ((int) ($chacara['id'] ?? 0) === (int) $item['id']) ? 'selected' : '' ?>>
                            <?= e($item['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                M&ecirc;s
                <input type="month" name="mes" value="<?= e($mesAtual ?? date('Y-m')) ?>">
            </label>
            <button class="btn btn-primary" type="submit">Ver m&ecirc;s</button>
        </form>
        <?php if ($chacara !== null): ?>
            <div class="month-actions">
                <a class="btn btn-outline btn-small" href="<?= url('/proprietario/disponibilidade/' . (int) $chacara['id'] . '?mes=' . $mesAnterior) ?>">Anterior</a>
                <a class="btn btn-outline btn-small" href="<?= url('/proprietario/disponibilidade/' . (int) $chacara['id'] . '?mes=' . $proximoMes) ?>">Pr&oacute;ximo</a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($chacara !== null): ?>
        <div class="availability-grid">
            <section class="panel-card">
                <div class="panel-card-heading">
                    <h2><?= e($chacara['nome']) ?>: <?= e($formatarData($inicio)) ?> a <?= e($formatarData($fim)) ?></h2>
                </div>
                <div class="availability-legend">
                    <span><i class="is-free"></i> Disponivel</span>
                    <span><i class="is-blocked"></i> Bloqueado</span>
                    <span><i class="is-reserved"></i> Reservado</span>
                </div>
                <div class="owner-calendar">
                    <?php foreach ($dias as $dia): ?>
                        <article class="calendar-day is-<?= e($dia['status']) ?>">
                            <span><?= e($dia['semana']) ?></span>
                            <strong><?= e($dia['dia']) ?></strong>
                            <small><?= e($statusLabel($dia['status'])) ?></small>
                            <?php if (!empty($dia['observacao']) && $dia['status'] !== 'disponivel'): ?>
                                <em><?= e($dia['observacao']) ?></em>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <aside class="panel-card availability-actions-card">
                <div class="panel-card-heading">
                    <h2>Alterar datas</h2>
                </div>
                <form class="owner-property-form availability-form" method="post" action="<?= url('/proprietario/disponibilidade/salvar') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="chacara_id" value="<?= (int) $chacara['id'] ?>">
                    <label>
                        A&ccedil;&atilde;o
                        <select name="acao">
                            <option value="bloquear">Bloquear datas</option>
                            <option value="liberar">Liberar datas bloqueadas</option>
                        </select>
                    </label>
                    <label>
                        Data inicial
                        <input type="date" name="data_inicio" value="<?= e($inicio) ?>" required>
                    </label>
                    <label>
                        Data final
                        <input type="date" name="data_fim" value="<?= e($inicio) ?>" required>
                    </label>
                    <label class="form-full">
                        Observa&ccedil;&atilde;o do bloqueio
                        <textarea name="observacao" rows="4" placeholder="Ex.: manutencao, uso particular, limpeza"></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit">Salvar disponibilidade</button>
                </form>
                <p class="availability-note">Datas reservadas por reserva confirmada n&atilde;o podem ser liberadas manualmente.</p>
            </aside>
        </div>
    <?php endif; ?>
<?php endif; ?>
