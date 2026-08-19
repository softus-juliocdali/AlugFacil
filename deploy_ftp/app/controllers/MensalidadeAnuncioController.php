<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\ConfiguracaoMensalidadeAnuncio;
use App\Models\MensalidadeAnuncio;
use App\Services\MensalidadeAnuncioService;
use App\Services\PrecificacaoReservaService;
use RuntimeException;
use Throwable;

final class MensalidadeAnuncioController extends Controller
{
    public function admin():void
    {
        Auth::requireRole('admin');
        $status=trim((string)($_GET['status']??''));$busca=trim((string)($_GET['busca']??''));
        $config=new ConfiguracaoMensalidadeAnuncio();
        $this->view('admin/mensalidades/index',[
            'title'=>'Mensalidades','panelRole'=>'admin','status'=>$status,'busca'=>$busca,
            'mensalidades'=>(new MensalidadeAnuncio())->listarAdministrativo($status,$busca),
            'configuracaoMensalidade'=>$config->atual(),'historicoConfiguracao'=>$config->historico(),
        ],'panel');
    }

    public function adminConfigUpdate():void
    {
        Auth::requireRole('admin');verify_csrf();
        try{
            $valor=PrecificacaoReservaService::decimalParaCentavos(str_replace(',','.',trim((string)($_POST['valor_padrao_mensal']??''))));
            (new ConfiguracaoMensalidadeAnuncio())->salvar($valor,(int)Auth::user()['id'],(string)($_POST['motivo']??''));
            flash('success','Valor padrao da mensalidade atualizado. Anuncios ja configurados nao foram alterados.');
        }catch(Throwable $e){flash('error',$e instanceof RuntimeException?$e->getMessage():'Nao foi possivel atualizar o valor padrao.');}
        $this->redirect('/admin/mensalidades');
    }

    public function owner():void
    {
        $p=Auth::requireProprietarioAutenticado();$m=new MensalidadeAnuncio();
        $this->view('proprietario/mensalidades/index',['title'=>'Mensalidade do anuncio','panelRole'=>'proprietario','mensalidades'=>$m->listarPorProprietario((int)$p['id']),'historico'=>$m->historicoPorProprietario((int)$p['id']),'notificacoes'=>$m->listarNotificacoes((int)Auth::user()['id'])],'panel');
    }
    public function pay(string $id):void
    {
        $p=Auth::requireProprietarioAutenticado();$chacara=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($chacara===false)$this->notFound();
        $itens=(new MensalidadeAnuncio())->listarPorProprietario((int)$p['id']);$item=null;foreach($itens as $i)if((int)$i['chacara_id']===(int)$chacara){$item=$i;break;}
        if(!$item)$this->notFound();$url=trim((string)($item['invoice_url']??''));if($url===''||!preg_match('#^https://([a-z0-9-]+\.)*asaas\.com(?:/|$)#i',$url)){flash('error','A cobranca ainda esta sendo preparada pelo Asaas. Tente novamente em instantes.');$this->redirect('/proprietario/mensalidades');}
        header('Location: '.$url,true,302);exit;
    }
    public function adminUpdate(string $id):void
    {
        Auth::requireRole('admin');verify_csrf();$chacara=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($chacara===false)$this->notFound();$ativa=($_POST['mensalidade']??'sem')==='com';
        try{$valor=$ativa?PrecificacaoReservaService::decimalParaCentavos(str_replace(',','.',trim((string)($_POST['valor_mensal']??'')))):null;(new MensalidadeAnuncioService())->configurar((int)$chacara,$ativa,$valor,(int)Auth::user()['id']);flash('success',$ativa?'Mensalidade configurada; a confirmacao do pagamento ocorrera por webhook.':'Mensalidade desativada.');}
        catch(Throwable $e){flash('error',$e instanceof RuntimeException?$e->getMessage():'Nao foi possivel configurar a mensalidade.');}
        $this->redirect('/admin/chacaras/'.(int)$chacara);
    }
    private function notFound():never{http_response_code(404);$this->view('public/404',['title'=>'Registro nao encontrado']);exit;}
}
