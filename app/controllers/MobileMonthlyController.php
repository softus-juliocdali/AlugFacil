<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Controller;
use App\Services\MonthlyBillingService;
use App\Services\MonthlyPaymentPresentation;
use App\Services\FinancialErrorMessage;

final class MobileMonthlyController extends Controller
{
    public function show(string $id):void
    {
        header('Cache-Control: no-store');
        $p=Auth::requireProprietarioAutenticado();
        try { $payment=(new MonthlyPaymentPresentation())->get($this->id($id),(int)$p['id']); }
        catch (\DomainException) { http_response_code(404);$this->view('public/404',['title'=>'Obrigação não encontrada']);return; }
        $this->view('proprietario/mensalidades/payment',['title'=>'Pagar mensalidade','panelRole'=>'proprietario','payment'=>$payment],'panel');
    }
    public function pay(string $id):void
    {
        $p=Auth::requireProprietarioAutenticado();verify_csrf();$oid=$this->id($id);
        header('Cache-Control: no-store');
        $input=$_POST;$_POST=[];$_REQUEST=[];
        try {
            $method=$input['method']??'PIX';
            if($method==='CREDIT_CARD') {
                if(getenv('APP_ENV')==='production' && ($_SERVER['HTTPS']??'')!=='on')throw new \RuntimeException('Pagamento por cartão exige conexão HTTPS.');
                (new \App\Services\MonthlyCardPayment())->pay($oid,(int)$p['id'],$input,(string)($_SERVER['REMOTE_ADDR']??''));
            } elseif($method==='PIX') (new MonthlyBillingService())->issue($oid,(int)$p['id'],'PIX');
            else throw new \RuntimeException('Meio de pagamento inválido.');
        }
        catch (\Throwable $e) { flash('error',FinancialErrorMessage::publicMessage($e,'Não foi possível preparar o pagamento. Tente novamente mais tarde.')); }
        finally { unset($input); }
        $this->redirect('/mobile/mensalidades/'.$oid);
    }
    private function id(string $id):int { if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new \DomainException('Obrigação não encontrada.');return (int)$id; }
}
