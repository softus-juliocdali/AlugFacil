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
        $p=Auth::requireProprietarioAutenticado();
        try { $payment=(new MonthlyPaymentPresentation())->get($this->id($id),(int)$p['id']); }
        catch (\DomainException) { http_response_code(404);$this->view('public/404',['title'=>'Obrigação não encontrada']);return; }
        $this->view('proprietario/mensalidades/payment',['title'=>'Pagar mensalidade','panelRole'=>'proprietario','payment'=>$payment],'panel');
    }
    public function pay(string $id):void
    {
        $p=Auth::requireProprietarioAutenticado();verify_csrf();$oid=$this->id($id);
        try { (new MonthlyBillingService())->issue($oid,(int)$p['id'],'PIX'); }
        catch (\Throwable $e) { flash('error',FinancialErrorMessage::publicMessage($e,'Não foi possível preparar o PIX. Tente novamente mais tarde.')); }
        $this->redirect('/mobile/mensalidades/'.$oid);
    }
    private function id(string $id):int { if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new \DomainException('Obrigação não encontrada.');return (int)$id; }
}
