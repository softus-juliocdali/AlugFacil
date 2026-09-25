<?php
$state=match(strtoupper($payment['estado'])) {'PENDENTE','PENDING'=>'Aguardando pagamento','OVERDUE'=>'Vencido','PAGA','RECEIVED','CONFIRMED'=>'Pago','CANCELADA','DELETED'=>'Cancelado','ESTORNADA','REFUNDED'=>'Estornado',default=>'Em processamento'};
?>
<div class="owner-financial-page owner-receipts-page">
<div class="panel-page-heading"><div><span>Financeiro</span><h1>Pagar mensalidade</h1><p>O pagamento é apresentado aqui no AlugFácil.</p></div></div>
<section class="panel-card">
<dl class="client-summary-list">
<div><dt>Valor</dt><dd>R$ <?= e(number_format($payment['valor_centavos']/100,2,',','.')) ?></dd></div>
<div><dt>Vencimento</dt><dd><?= e(date('d/m/Y',strtotime($payment['vencimento']))) ?></dd></div>
<div><dt>Estado</dt><dd><?= e($state) ?></dd></div>
</dl>
<?php if (!empty($payment['aviso'])): ?><p role="alert"><?= e($payment['aviso']) ?></p><?php endif; ?>
<?php if (!empty($payment['qr'])): ?><img alt="QR Code PIX da mensalidade" style="width:240px;max-width:100%;height:auto" src="data:image/png;base64,<?= e($payment['qr']) ?>"><?php endif; ?>
<?php if (!empty($payment['codigo'])): ?><label>PIX copia e cola<textarea readonly style="width:100%;min-height:120px" onclick="this.select()"><?= e($payment['codigo']) ?></textarea></label><p>Toque no código para selecionar e copiar no aplicativo do seu banco.</p><?php endif; ?>
<?php if (!empty($payment['expiracao'])): ?><p>Validade do PIX: <?= e($payment['expiracao']) ?></p><?php endif; ?>
<?php if ($payment['metodo']==='CREDIT_CARD' && in_array(strtoupper($payment['estado']),['PENDENTE','PENDING','OVERDUE'],true)): ?><p>Esta obrigação já possui pagamento por cartão iniciado. A captura de cartão para mensalidades ainda não está disponível no app. Não geraremos outra cobrança nem abriremos uma fatura hospedada. Consulte o suporte para acompanhar este pagamento.</p>
<?php elseif (!$payment['emitida'] && $payment['estado']==='pendente'): ?>
<form method="post" action="<?= url('/mobile/mensalidades/'.$payment['id']) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Gerar PIX desta mensalidade</button></form>
<?php endif; ?>
<div class="form-actions"><a class="btn btn-outline" href="<?= url('/mobile/mensalidades/'.$payment['id']) ?>">Atualizar estado</a><a class="btn btn-outline" href="<?= url('/proprietario/mensalidades') ?>">Voltar às mensalidades</a></div>
</section></div>
