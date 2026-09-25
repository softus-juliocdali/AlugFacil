<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/PublicApiTest.php';
require __DIR__.'/support/FinancialFixture.php';
use App\Core\Database;
use App\Api\MobileSessions;
use App\Models\User;
use App\Services\MonthlyPaymentPresentation;

$db=Database::getConnection();$f=new FinancialFixture($db);$server=null;$sessions=new MobileSessions($db);$pairs=[];
try {
    foreach(['proprietario','cliente'] as $role)$pairs[$role]=$sessions->create((new User())->findById($f->users[$role]),'Owner integration test');
    apiCheck($pairs['proprietario']['user']['tipo_usuario']==='proprietario','Owner role preserved in token DTO');
    $server=new PublicApiTestServer();
    $favorites=function(string $path='',string $method='GET',string $role='cliente')use($server,&$pairs){return $server->request('/api/v1/favoritos'.$path,$method,['Authorization: Bearer '.$pairs[$role]['access_token'],'Content-Type: application/json'],$method==='POST'?'{}':null);};
    apiCheck($server->request('/api/v1/favoritos')['status']===401,'Favorites require authentication');
    foreach([1,2] as $_)apiCheck($favorites('/'.$f->property.'/salvar','POST')['json']['data']['favorito']===true,'Saving favorite is idempotent');
    $favoriteList=$favorites()['json']['data'];
    apiCheck($favoriteList['ids']===[$f->property] && $favoriteList['imoveis'][0]['id']===$f->property,'Favorites return existing public property DTO');
    apiCheck($favorites('','GET','proprietario')['json']['data']['ids']===[],'Favorites isolated between accounts');
    $renewed=$sessions->refresh($pairs['cliente']['refresh_token']);$pairs['cliente']=$renewed;
    apiCheck($favorites()['json']['data']['ids']===[$f->property],'Favorites persist after session renewal');
    foreach([1,2] as $_)apiCheck($favorites('/'.$f->property.'/remover','POST')['json']['data']['favorito']===false,'Removing favorite is idempotent');
    apiCheck($favorites()['json']['data']['ids']===[],'Favorite removed from database');
    apiCheck($favorites('/2147483647/salvar','POST')['status']===404,'Unavailable property cannot be favorited');
    $issue=function(string $path,string $role='proprietario')use($server,$pairs){return $server->request('/api/v1/mobile/web-session','POST',['Authorization: Bearer '.$pairs[$role]['access_token'],'Content-Type: application/json'],json_encode(['path'=>$path]));};
    apiCheck($issue('/proprietario/dashboard','cliente')['status']===422,'Client cannot exchange owner destination');
    apiCheck($issue('https://evil.invalid')['status']===422,'External destination rejected');
    apiCheck($issue('/reserva/confirmacao/2')['status']===422,'Private checkout cannot be selected by ID in bridge');
    $issued=$issue('/proprietario/dashboard');
    apiCheck($issued['status']===200&&!isset($issued['headers']['set-cookie']),'Ticket issued without changing Web session');
    $ticket=$issued['json']['data']['ticket'];
    $entered=$server->request('/mobile/entrar','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['ticket'=>$ticket]));
    apiCheck($entered['status']===303,'Ticket enters the existing owner Web flow');
    $cookie=explode(';',end($entered['headers']['set-cookie']))[0];
    apiCheck($server->request('/mobile/entrar','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['ticket'=>$ticket]))['status']===401,'Ticket cannot be replayed');
    foreach(['/proprietario/dashboard','/proprietario/chacaras','/proprietario/chacaras/criar','/proprietario/chacaras/editar/'.$f->property,'/proprietario/chacaras/fotos/'.$f->property,'/proprietario/disponibilidade/'.$f->property,'/proprietario/faturamento','/proprietario/mensalidades','/proprietario/recebimentos','/proprietario/dados-cadastrais','/cliente/historico'] as $path)apiCheck($server->request($path,'GET',['Cookie: '.$cookie])['status']===200,'Owner integrated screen '.$path);
    $rid=$f->consume($f->quote(230));
    apiCheck($server->request('/reserva/confirmacao/'.$rid,'GET',['Cookie: '.$cookie])['status']===404,'Property owner cannot access guest checkout');
    apiCheck($server->request('/proprietario/reservas/'.$rid,'GET',['Cookie: '.$cookie])['status']===200,'Property owner can view their reservation management detail');
    apiCheck($server->request('/proprietario/chacaras/editar/2147483647','GET',['Cookie: '.$cookie])['status']===404,'Unknown property is not accessible');
    $form=$server->request('/proprietario/chacaras/editar/'.$f->property,'GET',['Cookie: '.$cookie]);
    preg_match('/name="_token" value="([^"]+)"/',$form['body'],$csrf);
    $post=function(string $path,array $data)use($server,$cookie,$csrf){return $server->request($path,'POST',['Cookie: '.$cookie,'Content-Type: application/x-www-form-urlencoded'],http_build_query(['_token'=>$csrf[1]]+$data));};
    $changed=$post('/proprietario/chacaras/status/'.$f->property,['status'=>'indisponivel']);
    apiCheck($changed['status']===302 && (new App\Models\Chacara())->buscarDoProprietario($f->property,$f->owner)['status_operacional']==='indisponivel','Owner mobile session can pause new reservations');
    $day=(new DateTimeImmutable('today'))->modify('+420 days')->format('Y-m-d');
    $blocked=$post('/proprietario/disponibilidade/salvar',['chacara_id'=>$f->property,'acao'=>'bloquear','data_inicio'=>$day,'data_fim'=>$day]);
    $q=$db->prepare("SELECT count(*) FROM disponibilidades WHERE chacara_id=:c AND data=:d AND status='bloqueado'");$q->execute(['c'=>$f->property,'d'=>$day]);
    apiCheck($blocked['status']===302 && (int)$q->fetchColumn()===1,'Date blocking remains separate from operational availability');
    $post('/proprietario/disponibilidade/salvar',['chacara_id'=>$f->property,'acao'=>'liberar','data_inicio'=>$day,'data_fim'=>$day]);
    $post('/proprietario/chacaras/status/'.$f->property,['status'=>'disponivel']);
    $q=$db->prepare('SELECT proprietario_id,chacara_id FROM reservas WHERE id=:id');$q->execute(['id'=>$rid]);$reservation=$q->fetch();
    apiCheck((int)$reservation['proprietario_id']===$f->owner && (int)$reservation['chacara_id']===$f->property,'Existing reservation relationship unchanged by property actions');
    $edited=$post('/proprietario/chacaras/editar/'.$f->property,['nome'=>'Owner mobile edited','tipo_imovel'=>'chacara','valor_diaria'=>'100.00','cidade'=>'Sao Paulo','estado'=>'SP','endereco'=>'Test','checkin_hora_inicial'=>'14:00','checkin_hora_final'=>'18:00','checkout_hora_inicial'=>'08:00','checkout_hora_final'=>'11:00']);
    apiCheck($edited['status']===302 && (new App\Models\Chacara())->buscarDoProprietario($f->property,$f->owner)['nome']==='Owner mobile edited','Property editing reuses Web validation and persistence');
    $boundary='OwnerPhotoTest'.bin2hex(random_bytes(6));
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
    $body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"_token\"\r\n\r\n".$csrf[1]."\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"fotos[]\"; filename=\"owner-test.png\"\r\nContent-Type: image/png\r\n\r\n".$png."\r\n--".$boundary."--\r\n";
    $upload=$server->request('/proprietario/chacaras/fotos/'.$f->property,'POST',['Cookie: '.$cookie,'Content-Type: multipart/form-data; boundary='.$boundary],$body);
    $q=$db->prepare('SELECT id FROM chacara_fotos WHERE chacara_id=:c');$q->execute(['c'=>$f->property]);$photo=$q->fetchColumn();
    apiCheck($upload['status']===302 && (bool)$photo,'Authenticated multipart photo upload uses existing image validation');
    $post('/proprietario/chacaras/fotos/'.$f->property,['acao'=>'remover','foto_id'=>$photo]);
    $q=$db->prepare("INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,valor_centavos,vencimento,estado,versao_global,versao_imovel,percentual_afiliado_bps,comissao_afiliado_centavos) VALUES(:c,:p,1500,'2030-02-01','pendente',1,1,0,0) RETURNING id");$q->execute(['c'=>$f->property,'p'=>$f->owner]);$monthlyId=(int)$q->fetchColumn();
    $monthlyPage=$server->request('/mobile/mensalidades/'.$monthlyId,'GET',['Cookie: '.$cookie]);
    apiCheck($monthlyPage['status']===200 && str_contains($monthlyPage['body'],'monthly-card-form') && str_contains($monthlyPage['body'],'Gerar PIX') && !str_contains($monthlyPage['body'],'sandbox.asaas.com/i/'),'Transparent monthly PIX/card form inside authenticated app');
    apiCheck(in_array('no-store',$monthlyPage['headers']['cache-control']??[],true),'Card screen is never cached');
    $noCsrf=$server->request('/mobile/mensalidades/'.$monthlyId,'POST',['Cookie: '.$cookie,'Content-Type: application/x-www-form-urlencoded'],'method=CREDIT_CARD');
    apiCheck($noCsrf['status']===302 && str_ends_with($noCsrf['headers']['location'][0],'/login'),'Monthly capture without CSRF returns to login');
    $deniedMonthly=$post('/mobile/mensalidades/'.$monthlyId,['method'=>'PIX']);
    apiCheck($deniedMonthly['status']===302 && $db->query('SELECT forma_pagamento FROM obrigacoes_mensalidades WHERE id='.$monthlyId)->fetchColumn()===null,'Blocked monthly emission leaves instrument unselected');
    $sessions->logout($pairs['proprietario']['refresh_token']);
    apiCheck($server->request('/proprietario/dashboard','GET',['Cookie: '.$cookie])['status']===302,'Mobile logout invalidates integrated Web session');

    // Read-only gateway double: no outbound request and no real monthly charge.
    $db->beginTransaction();
    $q=$db->prepare("INSERT INTO obrigacoes_mensalidades(chacara_id,proprietario_id,valor_centavos,vencimento,estado,forma_pagamento,asaas_payment_id,versao_global,versao_imovel,percentual_afiliado_bps,comissao_afiliado_centavos) VALUES(:c,:p,1500,'2030-01-01','pendente','PIX','monthly-test',1,1,0,0) RETURNING id");$q->execute(['c'=>$f->property,'p'=>$f->owner]);$id=(int)$q->fetchColumn();
    $reader=new class {public int $reads=0;public function consultarCobranca(string $id):array{$this->reads++;return ['id'=>$id,'value'=>'15.00','billingType'=>'PIX','status'=>'PENDING'];}public function consultarQrCodePix(string $id):array{return ['encodedImage'=>base64_encode('fake-image'),'payload'=>'PIX-TEST','expirationDate'=>'2030-01-01'];}};
    $presentation=new MonthlyPaymentPresentation($db,$reader);
    $first=$presentation->get($id,$f->owner);$second=$presentation->get($id,$f->owner);
    apiCheck($first===$second&&$first['codigo']==='PIX-TEST'&&!isset($first['invoice_url']),'Monthly PIX reopening returns same data without hosted invoice');
    $reads=$reader->reads;$denied=false;try{$presentation->get($id,$f->owner+1000000);}catch(DomainException){$denied=true;}
    apiCheck($denied&&$reader->reads===$reads,'Monthly ownership checked before gateway access');
    $db->commit();
    $billing=new App\Services\MonthlyBillingService($db);
    $charge1=$billing->issue($id,$f->owner,'PIX');$charge2=$billing->issue($id,$f->owner,'PIX');
    apiCheck($charge1['asaas_payment_id']==='monthly-test' && $charge2['asaas_payment_id']===$charge1['asaas_payment_id'],'Existing monthly charge reused on repeated payment action');
} finally {
    if($db->inTransaction())$db->rollBack();
    $server?->close();
    foreach($f->users as $id){$db->prepare('DELETE FROM mobile_access_tokens WHERE session_id IN (SELECT id FROM mobile_sessions WHERE usuario_id=:u)')->execute(['u'=>$id]);$db->prepare('DELETE FROM mobile_refresh_tokens WHERE session_id IN (SELECT id FROM mobile_sessions WHERE usuario_id=:u)')->execute(['u'=>$id]);$db->prepare('DELETE FROM mobile_sessions WHERE usuario_id=:u')->execute(['u'=>$id]);}
    $db->prepare('DELETE FROM obrigacoes_mensalidades WHERE chacara_id=:c')->execute(['c'=>$f->property]);
    $f->close();
}
