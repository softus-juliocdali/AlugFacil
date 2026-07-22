<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Models\ConfiguracaoFinanceira;use Throwable;
final class FinanceConfigController extends Controller
{
 public function index():void{Auth::requireRole('admin');$m=new ConfiguracaoFinanceira();$this->view('admin/configuracoes_financeiras/index',['title'=>'Configuracoes financeiras','panelRole'=>'admin','config'=>$m->atual(),'historico'=>$m->historico()],'panel');}
 public function store():void{Auth::requireRole('admin');verify_csrf();try{$taxas=[];$formas=$_POST['forma_pagamento']??[];foreach($formas as$k=>$f)$taxas[]=['forma_pagamento'=>strtoupper((string)$f),'quantidade_parcelas'=>(int)($_POST['parcelas'][$k]??1),'percentual_gateway_bps'=>(int)($_POST['gateway_bps'][$k]??-1),'taxa_fixa_gateway_centavos'=>(int)($_POST['gateway_fixa'][$k]??-1),'margem_seguranca_bps'=>(int)($_POST['margem_bps'][$k]??-1),'ativo'=>isset($_POST['taxa_ativa'][$k])];$v=(new ConfiguracaoFinanceira())->salvar(['taxa_plataforma_percentual_bps'=>(int)($_POST['plataforma_bps']??-1),'taxa_plataforma_fixa_centavos'=>(int)($_POST['plataforma_fixa']??-1),'precificacao_ativa'=>isset($_POST['precificacao_ativa'])],$taxas,(int)Auth::user()['id'],(string)($_POST['motivo']??''));flash('success','Configuracao financeira versao '.$v.' salva.');}catch(Throwable$e){flash('error',$e->getMessage());}$this->redirect('/admin/configuracoes-financeiras');}
}
