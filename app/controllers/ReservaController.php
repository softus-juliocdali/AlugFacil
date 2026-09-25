<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Models\Chacara;use App\Models\Reserva;use App\Services\PrecificacaoReservaService;use App\Helpers\AsaasHelper;use DateTimeImmutable;use DateTimeZone;use RuntimeException;use Throwable;
final class ReservaController extends Controller
{
 public function create(string$id):void{Auth::requireGuest();$rid=$this->id($id);$m=new Reserva();$c=$m->buscarChacaraParaReserva($rid);if(!$c)$this->notFound();$this->autorizarImovel($rid);$i=old('data_inicio',$this->dataQuery('data_inicio'));$f=old('data_fim',$this->dataQuery('data_fim'));$n=Reserva::periodoValido($i,$f)?Reserva::calcularDiarias($i,$f):0;$d=PrecificacaoReservaService::decimalParaCentavos((string)$c['valor_diaria']);$this->render($c,$i,$f,$n,$d*$n,null,null);}
 public function store(string$id):void
 {
  Auth::requireGuest();verify_csrf();$rid=$this->id($id);$user=(int)Auth::user()['id'];
  $i=trim((string)($_POST['data_inicio']??''));$f=trim((string)($_POST['data_fim']??''));$qid=trim((string)($_POST['cotacao_id']??''));set_old(['data_inicio'=>$i,'data_fim'=>$f]);
  try{
   $service=new \App\Services\CheckoutQuoteService();
   if($qid===''){
    $mode=trim((string)($_POST['modalidade']??'integral'));$method=strtoupper(trim((string)($_POST['forma_pagamento']??'PIX')));
    $q=$service->create($user,$rid,$i,$f,$mode,$method);$s=$q['detalhes'];$s['expira_em']=$q['expira_em'];
    $c=(new Reserva())->buscarChacaraParaReserva($rid);if(!$c)throw new RuntimeException('Imovel indisponivel.');
    $late=new DateTimeImmutable('now')>=new DateTimeImmutable($s['cancelamento_permitido_ate']);
    $this->render($c,$i,$f,$s['quantidade_diarias'],$s['total_centavos'],$s,$q['id'],$late);return;
   }
   $r=$service->consume($qid,$user,$rid,$i,$f,isset($_POST['aceite_reserva_menos_24h']));
   clear_old();flash('success','Reserva criada com os valores da cotacao. Conclua o pagamento dentro do prazo do checkout.');$this->redirect('/reserva/confirmacao/'.$r['id']);
  }catch(Throwable $e){$this->erro(\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel criar a reserva agora.'),$rid);}
 }
 public function confirmation(string$id):void{Auth::requireGuest();$rid=$this->id($id);$r=(new Reserva())->buscarConfirmacao($rid,(int)Auth::user()['id']);if(!$r)$this->notFound();$this->view('public/reserva_confirmacao',['title'=>'Confirmacao da reserva #'.$rid.' | Alug Facil','reserva'=>$r,'pagamento'=>(new \App\Services\ReservationPaymentPresentation())->get($rid,(int)Auth::user()['id'],max(0,(int)($_GET['parcela']??0))),'pagador'=>(new \App\Services\PayerDocumentService())->get((int)Auth::user()['id']),'obrigacoes'=>(new \App\Services\InstallmentScheduleService())->obligations($rid,(int)Auth::user()['id'])]);}
 public function retryPayment(string$id):void
 {
  Auth::requireGuest();verify_csrf();$rid=$this->id($id);$user=(int)Auth::user()['id'];
   try{(new \App\Services\ReservationBillingService())->issue($rid,$user,isset($_POST['autorizar_recorrencia']),$_SERVER['REMOTE_ADDR']??null);flash('success','Cobranca criada. O pagamento sera confirmado pelo Asaas.');}
  catch(Throwable $e){app_log('Falha segura no pagamento da reserva '.$rid);flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel gerar o pagamento agora.'));}
  $this->redirect('/reserva/confirmacao/'.$rid);
 }
 private function autorizarImovel(int $id):void {try{(new \App\Services\ReservationAuthorization())->assertCanBook((int)Auth::user()['id'],$id);}catch(\DomainException $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel concluir a operacao.'));$this->redirect('/chacara/'.$id);}}
 private function render(array$c,string$i,string$f,int$n,int$total,?array$q,?string$qid,bool$menos24=false):void
 {$timezone=new DateTimeZone('America/Sao_Paulo');$minData=new DateTimeImmutable('today',$timezone);$maxData=$minData->modify('+36 months');$linhas=(new Chacara())->buscarDatasIndisponiveis((int)$c['id'],$minData->format('Y-m-d'),$maxData->format('Y-m-d'));$datas=array_values(array_unique(array_map(static fn(array$linha):string=>(string)$linha['data'],$linhas)));sort($datas);
  $this->view('public/reserva_criar',['title'=>'Reservar '.$c['nome'].' | Alug Facil','chacara'=>$c,'dataInicio'=>$i,'dataFim'=>$f,'quantidadeDiarias'=>$n,'valorTotal'=>$total,'minDataInicio'=>$minData->format('Y-m-d'),'maxDataFim'=>$maxData->format('Y-m-d'),'datasIndisponiveis'=>$datas,'condicoesPagamento'=>(new \App\Services\PropertyPaymentConfigurationService())->effective((int)$c['id']),'cotacao'=>$q,'cotacaoId'=>$qid,'menos24h'=>$menos24]);}
 private function erro(string$m,int$id):never{flash('error',$m);$this->redirect('/reserva/criar/'.$id);}
 private function id(string$v):int{$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)$this->notFound();return(int)$id;}
 private function dataQuery(string$k):string{$v=trim((string)($_GET[$k]??''));return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:'';}
 private function notFound():never{http_response_code(404);$this->view('public/404',['title'=>'Reserva nao encontrada']);exit;}
}
