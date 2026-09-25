<?php
$dados=$dados??[];
$v=static fn(string $key,string $fallback=''):string=>e((string)old($key,(string)($dados[$key]??$fallback)));
?>
<header class="panel-page-heading"><div><span>Minha conta</span><h1>Dados Cadastrais</h1>
<p>Este cadastro também é necessário para receber os pagamentos das reservas. Mantenha seus dados e endereço atualizados.</p></div></header>
<section class="panel-card owner-form-card">
<form class="owner-property-form" method="post" action="<?= url('/proprietario/dados-cadastrais') ?>" data-owner-registration>
<?= csrf_field() ?>
<input type="hidden" name="versao_cadastro" value="<?= (int)($dados['versao']??0) ?>">
<?php if($conflitos): ?>
<div class="form-full form-error"><strong>Há divergências nos cadastros anteriores.</strong>
<p>Confira os campos: <?= e(implode(', ',array_column($conflitos,'campo'))) ?>. Os registros originais serão preservados.</p>
<label>Motivo da correção<input name="motivo_correcao" minlength="10" maxlength="500" value="<?= $v('motivo_correcao') ?>" required></label>
<label><input type="checkbox" name="confirmar_correcao" value="1" required> Revisei as divergências e confirmo os dados informados.</label></div>
<?php endif; ?>
<label>Tipo de pessoa<select name="tipo_pessoa" required><option value="">Selecione</option><?php foreach(['PF'=>'Pessoa física','PJ'=>'Pessoa jurídica'] as $key=>$label): ?><option value="<?= $key ?>" <?= $v('tipo_pessoa')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>CPF/CNPJ<input name="cpf_cnpj" value="<?= $v('cpf_cnpj') ?>" required inputmode="numeric" maxlength="18" autocomplete="off"></label>
<label class="form-full">Nome completo / Razão social<input name="nome_razao_social" value="<?= $v('nome_razao_social',$dados? '':$usuario['nome']) ?>" required maxlength="150"></label>
<label>Nome fantasia<input name="nome_fantasia" value="<?= $v('nome_fantasia') ?>" maxlength="150"></label>
<label data-person="PF">Data de nascimento (PF)<input type="date" name="data_nascimento" value="<?= $v('data_nascimento') ?>"></label>
<label data-person="PJ">Tipo de empresa (PJ)<select name="tipo_empresa"><option value="">Selecione</option><?php foreach(['MEI','LIMITED','INDIVIDUAL','ASSOCIATION'] as $x): ?><option value="<?= $x ?>" <?= $v('tipo_empresa')===$x?'selected':'' ?>><?= $x ?></option><?php endforeach; ?></select></label>
<label>Renda / Faturamento mensal (R$)<input name="renda_faturamento_mensal" value="<?= $v('renda_faturamento_mensal',isset($dados['renda_faturamento_mensal_centavos'])?number_format((int)$dados['renda_faturamento_mensal_centavos']/100,2,',',''):'') ?>" required inputmode="decimal" placeholder="0,00"></label>
<label>Telefone<input type="tel" name="telefone" value="<?= $v('telefone') ?>" maxlength="20"></label>
<label>Celular<input type="tel" name="celular" value="<?= $v('celular') ?>" required maxlength="20" autocomplete="tel"></label>
<label>E-mail de acesso e contato<input type="email" name="email_financeiro" value="<?= $v('email_financeiro',$dados?'':$usuario['email']) ?>" required maxlength="160" autocomplete="email"></label>
<label>CEP<input name="cep" value="<?= $v('cep') ?>" required inputmode="numeric" maxlength="9" autocomplete="postal-code" data-owner-cep></label>
<p class="form-full" role="status" aria-live="polite" data-cep-status>Confira seu endereço. Todos os campos permitem preenchimento manual.</p>
<?php foreach(['endereco'=>['Logradouro',180],'numero'=>['Número',20],'complemento'=>['Complemento',100],'bairro'=>['Bairro',100],'cidade'=>['Cidade',100],'estado'=>['UF',2]] as $key=>[$label,$max]): ?>
<label><?= $label ?><input name="<?= $key ?>" value="<?= $v($key) ?>" maxlength="<?= $max ?>" <?= $key!=='complemento'?'required':'' ?>></label>
<?php endforeach; ?>
<label>Nova senha<input type="password" name="senha" autocomplete="new-password" placeholder="Deixe em branco para manter a senha"></label>
<div class="form-actions"><button class="btn btn-primary" type="submit">Salvar dados cadastrais</button></div>
</form></section>
<script src="<?= asset('js/owner-registration.js') ?>" defer></script>
