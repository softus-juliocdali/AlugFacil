<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialGatewayFake.php';
require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;
use App\Services\ReservationBillingService;
use App\Services\ReservationPaymentPresentation;
$db=Database::getConnection();$f=new FinancialFixture($db);$fake=new FinancialGatewayFake($f->tag);
$reader=new class($fake) {
    public int $reads=0;public bool $fail=false;
    public function __construct(public FinancialGatewayFake $gateway){}
    public function consultarCobranca(string $id):array{$this->reads++;if($this->fail)throw new RuntimeException('private gateway error');return $this->gateway->payments[$id]+['bankSlipUrl'=>'https://sandbox.asaas.com/b/pdf/test'];}
    public function consultarQrCodePix(string $id):array{return ['encodedImage'=>base64_encode('test image'),'payload'=>'PIX-COPIA-COLA-TESTE','expirationDate'=>'2030-01-01 12:00:00'];}
    public function consultarLinhaDigitavel(string $id):array{return ['identificationField'=>'12345.67890 12345.678901','barCode'=>'1234567890','nossoNumero'=>'1234'];}
};
$check=static function(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo '[OK] '.$name.PHP_EOL;};
$limits=$db->query('SELECT * FROM configuracoes_parcelamento WHERE id=1')->fetch();
try {
    $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000,entrada_maxima_bps=8000 WHERE id=1');
    $billing=new ReservationBillingService($db,$fake);$service=new ReservationPaymentPresentation($db,$reader);$uid=$f->users['cliente'];
    foreach(['PIX','BOLETO','CREDIT_CARD'] as $i=>$method){
        $db->prepare('UPDATE configuracoes_comerciais_imoveis SET aceita_parcelamento=TRUE,entrada_bps=3000 WHERE chacara_id=:c')->execute(['c'=>$f->property]);
        $rid=$f->consume($f->quote(180+$i*10,$method==='BOLETO'?'entrada_parcelamento':'integral',$method));
        $one=$billing->issue($rid,$uid);$two=$billing->issue($rid,$uid);
        $check($one['asaas_payment_id']===$two['asaas_payment_id']&&$fake->paymentPosts===($i<2?$i+1:$i+2),$method.' emissao idempotente');
        $p=$service->get($rid,$uid);$service->get($rid,$uid);
        $check($fake->paymentPosts===($i<2?$i+1:$i+2),$method.' reabertura nao emite cobranca');
        $check($p['estado']==='PENDING'&&$p['valor_centavos']===$one['total_centavos'],'Valor e estado '.$method);
        if($method==='PIX')$check($p['codigo']==='PIX-COPIA-COLA-TESTE'&&!empty($p['qr']),'QR e copia e cola');
        if($method==='BOLETO')$check(!empty($p['codigo'])&&!empty($p['documento'])&&!isset($p['cartao_url']),'Linha digitavel e documento sem fatura hospedada');
        if($method==='CREDIT_CARD')$check(!empty($p['cartao_url'])&&!isset($p['creditCard']),'Captura de cartao existente preservada');
        $before=$reader->reads;
        try{$service->get($rid,$f->users['admin']);throw new RuntimeException('Autorizacao falhou');}catch(DomainException){$check($reader->reads===$before,'Outra identidade recusada antes de consultar gateway');}
        $check($service->get($rid,$uid,999)===null,'Parcela inexistente nao usa cobranca de outra reserva');
        $reserva=['id'=>$rid];$pagamento=$p;ob_start();require APP_ROOT.'/app/views/public/reserva_pagamento.php';$html=ob_get_clean();
        if($method!=='CREDIT_CARD')$check(!str_contains($html,'/i/test-only')&&str_contains($html,e($p['codigo'])),'HTML transparente '.$method);
        $reader->fail=true;$error=$service->get($rid,$uid);$reader->fail=false;
        $check(isset($error['aviso'])&&!str_contains(json_encode($error),'private gateway error'),'Falha segura sem dados internos');
        if($method==='BOLETO'){
            $paid=$fake->payments[$one['asaas_payment_id']];$paid['status']='RECEIVED';$paid['clientPaymentDate']=date('Y-m-d');
            $billing->processPayment([],$paid,'PAYMENT_RECEIVED');
            $oid=(int)$db->query('SELECT id FROM obrigacoes_reserva WHERE reserva_id='.$rid.' AND numero=1')->fetchColumn();
            $billing->issueScheduled($oid);$billing->issueScheduled($oid);
            $part=$service->get($rid,$uid,1);$service->get($rid,$uid,1);
            $check($part['numero']===1&&!empty($part['codigo'])&&$fake->paymentPosts===3,'Parcela usa sua propria cobranca sem reemissao');
        }
    }
} finally {$f->close();$db->prepare('UPDATE configuracoes_parcelamento SET entrada_minima_bps=:min,entrada_maxima_bps=:max WHERE id=1')->execute(['min'=>$limits['entrada_minima_bps'],'max'=>$limits['entrada_maxima_bps']]);}
