<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Models\ConfiguracaoFinanceira;use Throwable;
final class FinanceConfigController extends Controller
{
 public function index():void{Auth::requireRole('admin');$m=new ConfiguracaoFinanceira();$this->view('admin/configuracoes_financeiras/index',['title'=>'Configuracoes financeiras','panelRole'=>'admin','config'=>$m->atual(),'historico'=>$m->historico()],'panel');}
 public function store():void{Auth::requireRole('admin');verify_csrf();try{$taxa=(int)($_POST['taxa_operacao_pix_reserva_centavos']??-1);$v=(new ConfiguracaoFinanceira())->salvarOperacaoPix($taxa,(int)Auth::user()['id'],(string)($_POST['motivo']??''));flash('success','Configuracao financeira versao '.$v.' salva.');}catch(Throwable$e){flash('error',$e->getMessage());}$this->redirect('/admin/configuracoes-financeiras');}
}
