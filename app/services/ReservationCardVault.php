<?php
declare(strict_types=1);
namespace App\Services;
use PDO;use RuntimeException;

/** Only the hosted gateway handles PAN/CVV. This vault holds a customer-bound token. */
final class ReservationCardVault
{
 public function __construct(private PDO $db){}
 private function key():string
 {
  AsaasSubaccountVault::ready();
  return hex2bin((string)getenv('ASAAS_SUBACCOUNT_ENCRYPTION_KEY'));
 }
 public function consent(array $r,int $user,bool $accepted,?string $ip):void
 {
  $this->key();
  $q=$this->db->prepare('SELECT estado FROM autorizacoes_pagamento_reserva WHERE reserva_id=:r');$q->execute(['r'=>$r['id']]);
  if(in_array($q->fetchColumn(),['aguardando_autorizacao','ativa'],true))return;
  if(!$accepted||!filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException('Autorize as cobrancas das parcelas no cartao antes de emitir a entrada.');
  $this->db->prepare("INSERT INTO autorizacoes_pagamento_reserva(reserva_id,usuario_id,meio,texto_aceito,versao,ip_pagador) VALUES(:r,:u,'CREDIT_CARD',:text,'cartao-sandbox-v1',:ip)")->execute(['r'=>$r['id'],'u'=>$user,'ip'=>$ip,'text'=>'Autorizo cobrar cada parcela contratada no cartao utilizado na entrada, a partir do respectivo vencimento.']);
 }
 public function capture(array $r,array $payment,string $customer):void
 {
  $token=$payment['creditCard']['creditCardToken']??$payment['creditCardToken']??null;
  if(!is_string($token)||$token==='')return; // Never invent authorization when the gateway omits a token.
  $q=$this->db->prepare("SELECT a.*,f.conta_gateway FROM autorizacoes_pagamento_reserva a JOIN obrigacoes_reserva o ON o.reserva_id=a.reserva_id AND o.numero=0 JOIN operacoes_financeiras f ON f.id=o.operacao_id WHERE a.reserva_id=:r FOR UPDATE OF a");$q->execute(['r'=>$r['id']]);$a=$q->fetch();
  if(!$a||$a['estado']==='revogada'||in_array($r['status_reserva'],ReservaStatusService::FINAIS,true))return;
  if($a['estado']==='ativa')return;
  $nonce=random_bytes(12);$tag='';$aad='sandbox|'.$a['conta_gateway'].'|'.$r['id'].'|'.$customer;
  $cipher=openssl_encrypt($token,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
  if($cipher===false)throw new RuntimeException('Falha ao proteger autorizacao de cartao.');
  $this->db->prepare("UPDATE autorizacoes_pagamento_reserva SET estado='ativa',conta_gateway=:scope,asaas_customer_id=:customer,segredo_cifrado=:secret,nonce=:nonce,tag=:tag WHERE reserva_id=:r")->execute(['scope'=>$a['conta_gateway'],'customer'=>$customer,'secret'=>base64_encode($cipher),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'r'=>$r['id']]);
 }
 public function credentials(int $reservation,string $scope,string $customer):array
 {
  $q=$this->db->prepare("SELECT a.* FROM autorizacoes_pagamento_reserva a JOIN reservas r ON r.id=a.reserva_id WHERE a.reserva_id=:r AND a.estado='ativa' AND a.ambiente='sandbox' AND a.conta_gateway=:scope AND a.asaas_customer_id=:customer AND r.status_reserva='confirmada'");$q->execute(['r'=>$reservation,'scope'=>$scope,'customer'=>$customer]);$a=$q->fetch();
  if(!$a)throw new RuntimeException('Token autorizado indisponivel; concilie a entrada hospedada.');
  $token=openssl_decrypt(base64_decode($a['segredo_cifrado'],true),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($a['nonce'],true),base64_decode($a['tag'],true),'sandbox|'.$scope.'|'.$reservation.'|'.$customer);
  if($token===false)throw new RuntimeException('Autorizacao cifrada invalida.');
  return ['creditCardToken'=>$token,'remoteIp'=>$a['ip_pagador']];
 }
}
