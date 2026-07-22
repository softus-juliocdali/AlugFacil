<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\AsaasHelper;
use App\Models\Reserva;
use App\Services\PrecificacaoReservaService;
use RuntimeException;
use Throwable;

final class ReservaController extends Controller
{
    public function create(string $chacaraId):void
    {
        Auth::requireRole('cliente');$id=$this->id($chacaraId);$m=new Reserva();$c=$m->buscarChacaraParaReserva($id);if(!$c)$this->notFound();
        $inicio=old('data_inicio',$this->dataQuery('data_inicio'));$fim=old('data_fim',$this->dataQuery('data_fim'));$n=Reserva::periodoValido($inicio,$fim)?Reserva::calcularDiarias($inicio,$fim):0;
        $diaria=PrecificacaoReservaService::decimalParaCentavos((string)$c['valor_diaria']);
        $this->render($c,$inicio,$fim,$n,$diaria*$n,null,null);
    }

    public function store(string $chacaraId):void
    {
        Auth::requireRole('cliente');verify_csrf();$id=$this->id($chacaraId);$inicio=trim((string)($_POST['data_inicio']??''));$fim=trim((string)($_POST['data_fim']??''));$forma=strtoupper(trim((string)($_POST['forma_pagamento']??'')));$parcelas=(int)($_POST['quantidade_parcelas']??1);$cotacaoId=trim((string)($_POST['cotacao_id']??''));set_old(['data_inicio'=>$inicio,'data_fim'=>$fim]);
        $m=new Reserva();$c=$m->buscarChacaraParaReserva($id);if(!$c)$this->notFound();
        if(!Reserva::periodoValido($inicio,$fim)){flash('error','Informe uma data inicial futura e menor que a data final.');$this->redirect('/reserva/criar/'.$id);}
        if($m->existeIndisponibilidade($id,$inicio,$fim)||$m->existeConflitoReserva($id,$inicio,$fim)){flash('error','O periodo escolhido nao esta disponivel.');$this->redirect('/reserva/criar/'.$id);}
        $n=Reserva::calcularDiarias($inicio,$fim);$svc=new PrecificacaoReservaService();$diaria=PrecificacaoReservaService::decimalParaCentavos((string)$c['valor_diaria']);
        try{
            if($cotacaoId===''){$snap=$svc->cotar($diaria,$n,$forma,$parcelas);$qid=$m->criarCotacao((int)Auth::user()['id'],$id,$inicio,$fim,$snap);$this->render($c,$inicio,$fim,$n,$snap['valor_total_cliente_centavos'],$snap,$qid);return;}
            $q=$m->buscarCotacaoValida($cotacaoId,(int)Auth::user()['id'],$id);
            if($q['data_inicio']!==$inicio||$q['data_fim']!==$fim||$q['forma_pagamento']!==$forma||(int)$q['quantidade_parcelas']!==$parcelas)throw new RuntimeException('Dados enviados divergem da cotacao.');
            $snap=$svc->cotar($diaria,$n,$forma,$parcelas);if($this->normalizarSnapshot($snap)!==$this->normalizarSnapshot($q['detalhes']))throw new RuntimeException('Os valores foram atualizados. Solicite uma nova cotacao.');
            $m->marcarCotacaoConsumida($cotacaoId,(int)Auth::user()['id']);
            $r=$m->criar(array_merge(['usuario_id'=>(int)Auth::user()['id'],'proprietario_id'=>(int)$c['proprietario_id'],'chacara_id'=>$id,'data_inicio'=>$inicio,'data_fim'=>$fim,'quantidade_diarias'=>$n,'valor_diaria'=>PrecificacaoReservaService::centavosParaDecimal($diaria),'valor_total'=>PrecificacaoReservaService::centavosParaDecimal($snap['valor_hospedagem_centavos']),'precificacao_detalhes'=>$snap],$snap));
            $this->gerarCobrancaAsaas($m,(int)$r['id']);clear_old();flash('success','Reserva criada. Finalize o pagamento para confirmar sua estadia.');$this->redirect('/reserva/confirmacao/'.$r['id']);
        }catch(Throwable $e){flash('error',$e instanceof RuntimeException?$e->getMessage():'Nao foi possivel criar a reserva agora.');$this->redirect('/reserva/criar/'.$id);}
    }

    public function confirmation(string $reservaId):void{Auth::requireRole('cliente');$id=$this->id($reservaId);$r=(new Reserva())->buscarConfirmacao($id,(int)Auth::user()['id']);if(!$r)$this->notFound();$this->view('public/reserva_confirmacao',['title'=>'Confirmacao da reserva #'.$id.' | Alug Facil','reserva'=>$r]);}
    public function retryPayment(string $reservaId):void{Auth::requireRole('cliente');verify_csrf();$id=$this->id($reservaId);$m=new Reserva();$r=$m->buscarConfirmacao($id,(int)Auth::user()['id']);if(!$r)$this->notFound();if($r['status_reserva']!=='aguardando_pagamento'||$r['status_pagamento']==='pago'||empty($r['expira_em'])||strtotime($r['expira_em'])<=time()||!empty($r['id_cobranca_asaas'])){flash('error','Esta reserva nao permite gerar uma nova cobranca.');$this->redirect('/reserva/confirmacao/'.$id);}$this->gerarCobrancaAsaas($m,$id);$this->redirect('/reserva/confirmacao/'.$id);}

    private function render(array $c,string $i,string $f,int $n,int $total,?array $q,?string $qid):void{$this->view('public/reserva_criar',['title'=>'Reservar '.$c['nome'].' | Alug Facil','chacara'=>$c,'dataInicio'=>$i,'dataFim'=>$f,'quantidadeDiarias'=>$n,'valorTotal'=>$total,'minDataInicio'=>date('Y-m-d'),'meiosPagamento'=>(new PrecificacaoReservaService())->configuracoesPagamento(),'cotacao'=>$q,'cotacaoId'=>$qid]);}
    private function gerarCobrancaAsaas(Reserva $m,int $id):void{$h=new AsaasHelper();if(!$h->configurado()){flash('error','Reserva criada, mas o pagamento online ainda nao esta configurado.');return;}$r=$m->buscarParaCobranca($id);if(!$r)return;try{$c=$h->criarCobranca(['id'=>(int)$r['id'],'valor_total_centavos'=>(int)$r['valor_total_cliente_centavos'],'billing_type'=>$r['forma_pagamento'],'quantidade_parcelas'=>(int)$r['quantidade_parcelas'],'data_inicio'=>$r['data_inicio'],'data_fim'=>$r['data_fim'],'data_vencimento'=>date('Y-m-d',strtotime('+1 day')),'chacara_nome'=>$r['chacara_nome'],'cliente'=>['id'=>(int)$r['cliente_id'],'nome'=>$r['cliente_nome'],'email'=>$r['cliente_email'],'telefone'=>$r['cliente_telefone']??'']]);$link=(string)($c['invoiceUrl']??$c['bankSlipUrl']??'');if(empty($c['id'])||$link==='')throw new RuntimeException('Cobranca criada sem link.');$m->atualizarCobrancaAsaas($id,(string)$c['id'],$link);}catch(Throwable$e){AsaasHelper::logErro('Erro ao criar cobranca da reserva #'.$id.': '.$e->getMessage());flash('error','Reserva criada, mas nao foi possivel gerar o pagamento agora.');}}
    private function id(string $v):int{$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)$this->notFound();return(int)$id;}
    private function dataQuery(string $k):string{$v=trim((string)($_GET[$k]??''));return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:'';}
    private function normalizarSnapshot(array $snapshot):array{ksort($snapshot);foreach($snapshot as$k=>$v)if(is_array($v))$snapshot[$k]=$this->normalizarSnapshot($v);return$snapshot;}
    private function notFound():never{http_response_code(404);$this->view('public/404',['title'=>'Reserva nao encontrada']);exit;}
}
