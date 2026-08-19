<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Controller;
use App\Models\Reserva;
use App\Services\ReservaStatusService;
use App\Services\CancelamentoReservaService;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ReservaLifecycleController extends Controller
{
    public function cancelarCliente(string $id):void
    {
        Auth::requireRole('cliente');verify_csrf();$rid=$this->id($id);$m=new Reserva();$r=$m->buscarDetalheCliente($rid,(int)Auth::user()['id']);if(!$r)$this->naoEncontrada();
        $motivo=$this->motivo(false);
        try{$resultado=(new CancelamentoReservaService())->cancelarUsuario($rid,(int)Auth::user()['id'],null,$motivo);flash('success',empty($resultado['reembolso_id'])?'Reserva cancelada.':'Reserva cancelada e reembolso solicitado.');}catch(Throwable$e){flash('error',$e->getMessage());}
        $this->redirect('/cliente/reserva/'.$rid);
    }

    public function proprietarioDetalhe(string $id):void { $p=Auth::requireProprietarioOperacional();$m=new Reserva();$r=$m->buscarDetalheProprietario($this->id($id),(int)$p['id']);if(!$r)$this->naoEncontrada();$this->view('proprietario/reservas/show',['title'=>'Reserva #'.$r['id'],'panelRole'=>'proprietario','reserva'=>$r,'historico'=>$m->historico((int)$r['id'])],'panel'); }
    public function iniciarProprietario(string $id):void { $p=Auth::requireProprietarioOperacional();verify_csrf();$m=new Reserva();$r=$m->buscarDetalheProprietario($this->id($id),(int)$p['id']);if(!$r)$this->naoEncontrada();if(new DateTimeImmutable('today')<new DateTimeImmutable($r['data_inicio'].' -1 day')){flash('error','A locacao ainda nao pode ser iniciada.');$this->redirect('/proprietario/reservas/'.$r['id']);}$this->mudar((int)$r['id'],'em_andamento','proprietario',(int)Auth::user()['id'],null,'/proprietario/reservas/'.$r['id']); }
    public function finalizarProprietario(string $id):void { $p=Auth::requireProprietarioOperacional();verify_csrf();$m=new Reserva();$r=$m->buscarDetalheProprietario($this->id($id),(int)$p['id']);if(!$r)$this->naoEncontrada();if(new DateTimeImmutable('today')<new DateTimeImmutable($r['data_fim'])){flash('error','A locacao so pode ser finalizada a partir da data de saida.');$this->redirect('/proprietario/reservas/'.$r['id']);}$this->mudar((int)$r['id'],'finalizada','proprietario',(int)Auth::user()['id'],null,'/proprietario/reservas/'.$r['id']); }

    public function adminIndex():void { Auth::requireRole('admin');$status=trim((string)($_GET['status']??''));$m=new Reserva();$this->view('admin/reservas/index',['title'=>'Reservas','panelRole'=>'admin','reservas'=>$m->listarAdministrativas($status),'status'=>$status],'panel'); }
    public function adminDetalhe(string $id):void { Auth::requireRole('admin');$m=new Reserva();$r=$m->buscarDetalheAdministrativo($this->id($id));if(!$r)$this->naoEncontrada();$this->view('admin/reservas/show',['title'=>'Reserva #'.$r['id'],'panelRole'=>'admin','reserva'=>$r,'historico'=>$m->historico((int)$r['id'])],'panel'); }
    public function adminTransicao(string $id):void { Auth::requireRole('admin');verify_csrf();$rid=$this->id($id);$r=(new Reserva())->buscarDetalheAdministrativo($rid);if(!$r)$this->naoEncontrada();$acao=(string)($_POST['acao']??'');$novo=match($acao){'cancelar'=>'cancelada','aprovar_cancelamento'=>'cancelada','recusar_cancelamento'=>$this->statusRetorno($r),'iniciar'=>'em_andamento','finalizar'=>'finalizada',default=>''};if($novo===''){flash('error','Acao invalida.');$this->redirect('/admin/reservas/'.$rid);}$this->mudar($rid,$novo,'administrador',(int)Auth::user()['id'],$this->motivo(true),'/admin/reservas/'.$rid); }

    private function mudar(int $id,string $novo,string $tipo,int $uid,?string $motivo,string $volta):never {try{(new ReservaStatusService())->transicionar($id,$novo,['motivo'=>$motivo,'origem'=>$tipo==='administrador'?'administrador':'proprietario','responsavel_tipo'=>$tipo,'responsavel_id'=>$uid]);flash('success','Status da reserva atualizado.');}catch(Throwable $e){app_log($e->getMessage());flash('error','Transicao nao permitida para o status atual.');}$this->redirect($volta);}
    private function statusRetorno(array $r):string{return new DateTimeImmutable('today')>=new DateTimeImmutable($r['data_inicio'])?'em_andamento':'confirmada';}
    private function motivo(bool $obrigatorio):?string{$m=trim((string)($_POST['motivo']??''));$m=mb_substr($m,0,500);if($obrigatorio&&$m===''){flash('error','Informe o motivo administrativo.');$this->redirect($_SERVER['HTTP_REFERER']??'/admin/reservas');}return $m===''?null:$m;}
    private function id(string $id):int{$v=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($v===false)$this->naoEncontrada();return(int)$v;}
    private function naoEncontrada():never{http_response_code(404);$this->view('public/404',['title'=>'Reserva nao encontrada']);exit;}
}
