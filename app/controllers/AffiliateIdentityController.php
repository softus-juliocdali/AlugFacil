<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\AffiliateAuth;
use App\Core\Auth;
use App\Core\Controller;
use App\Services\AffiliateIdentityService;
use DomainException;
use Throwable;

final class AffiliateIdentityController extends Controller
{
    public function show():void
    {
        $a=AffiliateAuth::requireLogin();
        $this->view('afiliado/identidade',['title'=>'Vincular conta para reservas','principal'=>(new AffiliateIdentityService())->principal((int)$a['id'])]);
    }
    public function link():void
    {
        $a=AffiliateAuth::requireLogin();verify_csrf();
        try {
            if(($_POST['confirmar_vinculo']??'')!=='1')throw new DomainException('Confirme o vinculo entre as suas contas.');
            $service=new AffiliateIdentityService();
            $service->vincular((int)$a['id'],(string)($_POST['senha_afiliado']??''),(string)($_POST['email_principal']??''),(string)($_POST['senha_principal']??''));
            // Both credentials have just been verified. Future affiliate-only logins stay guest-only.
            Auth::login($service->principal((int)$a['id']));
            flash('success','Contas vinculadas. Historico e comissoes de afiliado foram preservados.');
            $this->redirect(Auth::consumeReturnPath());
        }catch(DomainException $e){flash('error',$e->getMessage());}catch(Throwable){flash('error','Nao foi possivel vincular as contas agora.');}
        $this->redirect('/afiliado/identidade');
    }
}
