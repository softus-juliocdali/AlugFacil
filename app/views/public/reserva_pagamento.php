<?php if (!empty($pagamento)): ?>
<section class="transparent-payment" aria-labelledby="payment-title">
    <h2 id="payment-title"><?= $pagamento['numero'] > 0 ? 'Parcela '.(int)$pagamento['numero'] : 'Pagamento da reserva' ?></h2>
    <dl class="confirmation-details">
        <div><dt>Valor</dt><dd>R$ <?= e(number_format($pagamento['valor_centavos']/100,2,',','.')) ?></dd></div>
        <div><dt>Vencimento</dt><dd><?= e(date('d/m/Y',strtotime($pagamento['vencimento']))) ?></dd></div>
        <div><dt>Estado da cobrança</dt><dd><?= e($pagamento['estado']) ?></dd></div>
    </dl>
    <?php if (!empty($pagamento['aviso'])): ?><p role="status"><?= e($pagamento['aviso']) ?></p><?php endif; ?>
    <?php if (!empty($pagamento['qr'])): ?><img src="data:image/png;base64,<?= e($pagamento['qr']) ?>" width="220" height="220" alt="QR Code PIX"><?php endif; ?>
    <?php if (!empty($pagamento['codigo'])): ?><label for="payment-code"><?= $pagamento['metodo']==='PIX'?'PIX copia e cola':'Linha digitável do boleto' ?></label><textarea id="payment-code" readonly rows="4" style="width:100%;box-sizing:border-box" onclick="this.select()" aria-describedby="payment-copy-hint"><?= e($pagamento['codigo']) ?></textarea><small id="payment-copy-hint">Selecione e copie o código para pagar no seu banco.</small><?php endif; ?>
    <?php if (!empty($pagamento['expiracao_pix'])): ?><p>Validade do QR Code: <?= e($pagamento['expiracao_pix']) ?>. Respeite também o prazo da reserva.</p><?php endif; ?>
    <?php if (!empty($pagamento['codigo_barras'])): ?><p style="overflow-wrap:anywhere">Código de barras: <?= e($pagamento['codigo_barras']) ?></p><?php endif; ?>
    <?php if (!empty($pagamento['nosso_numero'])): ?><p>Nosso número: <?= e($pagamento['nosso_numero']) ?></p><?php endif; ?>
    <?php if (!empty($pagamento['documento'])): ?><p><a class="btn btn-outline" href="<?= e($pagamento['documento']) ?>" target="_blank" rel="noopener noreferrer">Visualizar / imprimir boleto</a></p><?php endif; ?>
    <?php if (!empty($pagamento['cartao_url'])): ?><p><a class="btn btn-book" href="<?= e($pagamento['cartao_url']) ?>" target="_blank" rel="noopener noreferrer">Informar cartão no ambiente seguro Asaas</a></p><?php endif; ?>
    <p><a class="btn btn-outline" href="<?= url('/reserva/confirmacao/'.(int)$reserva['id'].'?parcela='.(int)$pagamento['numero']) ?>">Atualizar estado do pagamento</a></p>
</section>
<?php endif; ?>
