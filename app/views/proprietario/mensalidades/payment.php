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
<?php if (!$payment['emitida'] && $payment['estado']==='pendente' && $payment['metodo']!=='CREDIT_CARD'): ?>
<form method="post" action="<?= url('/mobile/mensalidades/'.$payment['id']) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Gerar PIX desta mensalidade</button></form>
<?php endif; ?>
<?php if (in_array(strtoupper($payment['estado']),['PENDENTE','PENDING','OVERDUE'],true) && in_array($payment['metodo'],[null,'CREDIT_CARD'],true)): ?>
<details><summary>Pagar com cartão de crédito</summary>
<p>Pagamento único de R$ <?= e(number_format($payment['valor_centavos']/100,2,',','.')) ?>. Os dados do cartão não são salvos no AlugFácil.</p>
<form method="post" action="<?= url('/mobile/mensalidades/'.$payment['id']) ?>" autocomplete="off" id="monthly-card-form">
<?= csrf_field() ?><input type="hidden" name="method" value="CREDIT_CARD">
<?php foreach ([['holderName','Nome impresso no cartão','text',180],['number','Número do cartão','text',23],['expiryMonth','Mês de validade (MM)','text',2],['expiryYear','Ano de validade (AAAA)','text',4],['ccv','Código de segurança','password',4],['name','Nome completo do titular','text',180],['email','E-mail do titular','email',180],['cpfCnpj','CPF/CNPJ do titular','text',18],['postalCode','CEP do titular','text',9],['addressNumber','Número do endereço','text',20],['phone','Telefone do titular com DDD','tel',20]] as [$key,$label,$type,$max]): ?>
<div class="form-group"><label for="monthly-<?= e($key) ?>"><?= e($label) ?></label><input id="monthly-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($type) ?>" maxlength="<?= $max ?>" required autocomplete="off" autocorrect="off" spellcheck="false" <?= in_array($key,['number','expiryMonth','expiryYear','ccv','cpfCnpj','postalCode','phone'],true)?'inputmode="numeric"':'' ?>></div>
<?php endforeach; ?>
<button class="btn btn-primary" type="submit">Pagar mensalidade com cartão</button>
</form></details>
<script>
const monthlyCardForm=document.getElementById('monthly-card-form');
monthlyCardForm.addEventListener('submit',()=>{const button=monthlyCardForm.querySelector('button');button.disabled=true;button.textContent='Processando pagamento…';});
window.addEventListener('pagehide',()=>monthlyCardForm.reset());
window.addEventListener('pageshow',()=>{monthlyCardForm.reset();const button=monthlyCardForm.querySelector('button');button.disabled=false;button.textContent='Pagar mensalidade com cartão';});
</script>
<?php endif; ?>
<div class="form-actions"><a class="btn btn-outline" href="<?= url('/mobile/mensalidades/'.$payment['id']) ?>">Atualizar estado</a><a class="btn btn-outline" href="<?= url('/proprietario/mensalidades') ?>">Voltar às mensalidades</a></div>
</section></div>
