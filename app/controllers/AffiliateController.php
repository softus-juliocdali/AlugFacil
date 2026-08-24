<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AffiliateAuth;
use App\Core\Controller;
use App\Models\Affiliate;
use App\Services\AffiliatePortalService;
use App\Validators\AffiliateValidator;
use RuntimeException;
use Throwable;

final class AffiliateController extends Controller
{
    public function index(): void
    {
        $sessionAffiliate = AffiliateAuth::requireLogin();
        $affiliate = (new Affiliate())->findById((int) $sessionAffiliate['id']);
        if ($affiliate === null) {
            AffiliateAuth::logout();
            $this->redirect('/afiliado/login');
        }
        $link = rtrim((string) config('app_url'), '/') . '/cadastro-proprietario?ref=' . rawurlencode($affiliate['codigo']);$portal=new AffiliatePortalService();
        $this->view('afiliado/dashboard', [
            'title' => 'Área do afiliado', 'panelRole' => 'afiliado',
            'affiliate' => $affiliate, 'referralLink' => $link,'dashboard'=>$portal->dashboard((int)$affiliate['id']),
        ], 'panel');
    }

    public function referred():void
    {
        $affiliate=AffiliateAuth::requireLogin();$filters=['nome'=>trim((string)($_GET['nome']??'')),'email'=>trim((string)($_GET['email']??'')),'de'=>trim((string)($_GET['de']??'')),'ate'=>trim((string)($_GET['ate']??''))];$page=max(1,(int)($_GET['pagina']??1));$this->view('afiliado/indicados',['title'=>'Meus indicados','panelRole'=>'afiliado','filters'=>$filters,'result'=>(new AffiliatePortalService())->referredOwners((int)$affiliate['id'],$filters,$page)],'panel');
    }

    public function referredDetail(string$id):void
    {
        $affiliate=AffiliateAuth::requireLogin();$ownerId=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$owner=$ownerId?(new AffiliatePortalService())->referredOwner((int)$affiliate['id'],(int)$ownerId):null;if($owner===null)$this->notFound();$this->view('afiliado/indicado_detalhe',['title'=>'Detalhe do indicado','panelRole'=>'afiliado','owner'=>$owner],'panel');
    }

    public function commissions():void
    {
        $affiliate=AffiliateAuth::requireLogin();$filters=['de'=>trim((string)($_GET['de']??'')),'ate'=>trim((string)($_GET['ate']??'')),'status'=>strtoupper(trim((string)($_GET['status']??''))),'proprietario'=>(int)($_GET['proprietario']??0),'chacara'=>trim((string)($_GET['chacara']??''))];$page=max(1,(int)($_GET['pagina']??1));$portal=new AffiliatePortalService();$this->view('afiliado/comissoes',['title'=>'Comissões','panelRole'=>'afiliado','filters'=>$filters,'result'=>$portal->commissions((int)$affiliate['id'],$filters,$page),'owners'=>$portal->ownerOptions((int)$affiliate['id']),'payments'=>$portal->receivedPayments((int)$affiliate['id']),'adjustments'=>$portal->adjustments((int)$affiliate['id']),'summary'=>(new \App\Services\AffiliateCommissionService())->summary((int)$affiliate['id'])],'panel');
    }

    public function profile():void
    {
        $session=AffiliateAuth::requireLogin();$affiliate=(new Affiliate())->findById((int)$session['id']);if($affiliate===null)$this->notFound();$this->view('afiliado/perfil',['title'=>'Meu perfil','panelRole'=>'afiliado','affiliate'=>$affiliate,'pixTypes'=>AffiliateValidator::PIX_TYPES],'panel');
    }

    public function updateProfile():void
    {
        $session=AffiliateAuth::requireLogin();verify_csrf();$id=(int)$session['id'];$model=new Affiliate();$current=$model->findById($id);if($current===null)$this->notFound();
        $data=['nome'=>trim((string)($_POST['nome']??'')),'telefone'=>trim((string)($_POST['telefone']??'')),'email'=>strtolower(trim((string)($_POST['email']??''))),'chave_pix'=>trim((string)($_POST['chave_pix']??'')),'tipo_chave_pix'=>trim((string)($_POST['tipo_chave_pix']??'')),'banco'=>trim((string)($_POST['banco']??''))];set_old($data);$currentPassword=(string)($_POST['senha_atual']??'');$newPassword=(string)($_POST['nova_senha']??'');$confirmation=(string)($_POST['nova_senha_confirmacao']??'');
        try{
            if(mb_strlen($data['nome'])<2||mb_strlen($data['nome'])>150)throw new RuntimeException('Informe um nome válido.');if(mb_strlen(preg_replace('/\D+/','',$data['telefone'])??'')<10)throw new RuntimeException('Informe um telefone válido.');if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');if(!in_array($data['tipo_chave_pix'],AffiliateValidator::PIX_TYPES,true)||!AffiliateValidator::pixKeyIsValid($data['tipo_chave_pix'],$data['chave_pix']))throw new RuntimeException('Informe uma chave PIX válida.');if(mb_strlen($data['banco'])>120)throw new RuntimeException('O banco excede o limite permitido.');if($model->identityExists($data['email'],(string)$current['cpf_cnpj'],$id))throw new RuntimeException('Este e-mail já está em uso.');
            $password=null;if($currentPassword!==''||$newPassword!==''||$confirmation!==''){$credentials=$model->findWithPasswordById($id);if(!$credentials||!password_verify($currentPassword,(string)$credentials['senha_hash']))throw new RuntimeException('A senha atual não confere.');if(strlen($newPassword)<8)throw new RuntimeException('A nova senha deve ter ao menos 8 caracteres.');if($newPassword!==$confirmation)throw new RuntimeException('A confirmação da nova senha não confere.');$password=$newPassword;}
            $model->updateOwnProfile($id,$data,$password);$fresh=$model->findById($id);if($fresh)AffiliateAuth::login($fresh);clear_old();flash('success','Perfil atualizado com sucesso.');
        }catch(Throwable$exception){flash('error',$exception instanceof RuntimeException?$exception->getMessage():'Não foi possível atualizar o perfil.');}
        $this->redirect('/afiliado/perfil');
    }

    private function notFound():never{http_response_code(404);$this->view('public/404',['title'=>'Conteúdo não encontrado']);exit;}
}
