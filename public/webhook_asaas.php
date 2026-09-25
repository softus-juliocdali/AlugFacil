<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
require APP_ROOT.'/app/helpers/functions.php';
spl_autoload_register(static function(string $class):void{$prefix='App\\';if(!str_starts_with($class,$prefix))return;$parts=explode('\\',substr($class,strlen($prefix)));$parts[0]=strtolower($parts[0]);$file=APP_ROOT.'/app/'.implode('/',$parts).'.php';if(is_file($file))require$file;});
ini_set('display_errors','0');error_reporting(E_ALL);
$app=require APP_ROOT.'/app/config/config.php';date_default_timezone_set($app['timezone']);$apis=require APP_ROOT.'/app/config/apis.php';
header('Content-Type: application/json; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
function respond(int $status,string $message):never{http_response_code($status);echo json_encode(['ok'=>$status===200,'message'=>$message],JSON_UNESCAPED_UNICODE);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');respond(405,'Metodo nao permitido.');}
$token=trim((string)($apis['asaas']['webhook_token']??''));$apiKey=trim((string)($apis['asaas']['api_key']??''));
if(strlen($token)<32||($apiKey!==''&&hash_equals($apiKey,$token))){app_log('Webhook Asaas indisponivel: token especifico ausente ou inseguro.');respond(500,'Webhook indisponivel.');}
$headers=[];if(function_exists('getallheaders')){foreach(getallheaders() as$k=>$v)$headers[strtolower((string)$k)]=(string)$v;}foreach($_SERVER as$k=>$v){if(str_starts_with($k,'HTTP_'))$headers[strtolower(str_replace('_','-',substr($k,5)))]=(string)$v;}
$provided=trim((string)($headers['asaas-access-token']??''));
if($provided===''||!hash_equals($token,$provided)){app_log('Autenticacao recusada no webhook Asaas.');respond(401,'Nao autorizado.');}
$max=(int)$apis['asaas']['webhook_max_body_bytes'];$declared=(int)($_SERVER['CONTENT_LENGTH']??0);if($declared>$max)respond(413,'Corpo da requisicao excede o limite.');
$raw=file_get_contents('php://input',false,null,0,$max+1);if($raw===false)respond(500,'Falha ao receber evento.');if(strlen($raw)>$max)respond(413,'Corpo da requisicao excede o limite.');if(trim($raw)==='')respond(400,'Corpo vazio.');
try{$payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){respond(400,'JSON invalido.');}
if(!is_array($payload)||array_is_list($payload))respond(422,'Objeto raiz invalido.');
$eventId=$payload['id']??null;$eventType=$payload['event']??null;if(!App\Models\AsaasWebhookEvento::validEventId($eventId))respond(422,'ID do evento invalido.');if(!is_string($eventType)||!preg_match('/^[A-Z_]{1,120}$/D',$eventType))respond(422,'Tipo do evento invalido.');
if(str_starts_with($eventType,'PAYMENT_')){if(!is_array($payload['payment']??null)||array_is_list($payload['payment']))respond(422,'Objeto payment invalido.');if(!is_string($payload['payment']['id']??null)||trim($payload['payment']['id'])==='')respond(422,'ID da cobranca invalido.');}
if(str_starts_with($eventType,'TRANSFER_')){if(!is_array($payload['transfer']??null)||!is_string($payload['transfer']['id']??null)||$payload['transfer']['id']==='')respond(422,'Objeto transfer invalido.');}
try{$result=(new App\Models\AsaasWebhookEvento())->receber($payload,$raw);if($result['created'])app_log('Evento Asaas persistido. event_id='.$eventId);elseif($result['divergent'])app_log('Evento Asaas duplicado divergente. event_id='.$eventId);else app_log('Evento Asaas duplicado. event_id='.$eventId);respond(200,$result['created']?'Evento recebido.':'Evento ja conhecido.');}
catch(Throwable){app_log('Falha privada ao persistir evento Asaas. event_id='.$eventId);respond(500,'Falha ao persistir evento.');}
