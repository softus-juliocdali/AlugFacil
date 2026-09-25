<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Services\PayerDocumentService;use App\Services\ReservationAuthorization;use RuntimeException;use Throwable;
final class PayerDocumentController extends Controller
{
 public function save(string $id):void
 {Auth::requireGuest();verify_csrf();$rid=(int)$id;$user=(int)Auth::user()['id'];try{(new ReservationAuthorization())->assertReservation($user,$rid,'reserva.pagar_proprias');(new PayerDocumentService())->save($user,(string)($_POST['tipo_pessoa']??''),(string)($_POST['cpf_cnpj']??''));flash('success','Documento do pagador salvo.');}catch(Throwable $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel salvar o documento.'));}$this->redirect('/reserva/confirmacao/'.$rid);}
}
