<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Controller;
use App\Services\CommercialConfigurationService;
use App\Services\MonthlyBillingService;
use App\Validators\AffiliateValidator;
use RuntimeException;
use Throwable;
final class CommercialController extends Controller
{
 public function installmentLimits():void
 {
  Auth::requireRole('admin');verify_csrf();try{$min=AffiliateValidator::commissionToBasisPoints((string)($_POST['entrada_minima']??''));$max=AffiliateValidator::commissionToBasisPoints((string)($_POST['entrada_maxima']??''));if($min===null||$max===null)throw new RuntimeException('Informe os limites percentuais.');(new \App\Services\PropertyPaymentConfigurationService())->saveLimits($min,$max,(int)Auth::user()['id']);flash('success','Faixa de entrada atualizada para novas ofertas de parcelamento.');}catch(Throwable $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel salvar os limites.'));}$this->redirect('/admin/configuracoes-financeiras');
 }
 public function ownerPayment(string $id):void
 {
  Auth::requireProprietarioOperacional();verify_csrf();$cid=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$cid){http_response_code(404);return;}
  try{$enabled=($_POST['aceita_parcelamento']??'')==='1';$bps=$enabled?AffiliateValidator::commissionToBasisPoints((string)($_POST['entrada']??'')):null;(new \App\Services\PropertyPaymentConfigurationService())->saveOwner($cid,(int)Auth::user()['id'],$enabled,$bps,(int)($_POST['versao']??-1));flash('success','Preferencia de pagamento salva para novos checkouts.');}catch(Throwable $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel salvar a preferencia.'));}$this->redirect('/proprietario/mensalidades');
 }
 public function property(string $id):void
 {
  Auth::requireRole('admin');verify_csrf();$cid=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$cid){http_response_code(404);return;}
  try{$bps=AffiliateValidator::commissionToBasisPoints((string)($_POST['comissao']??''));if($bps===null)throw new RuntimeException('Informe comissao entre 0 e 100%.');(new CommercialConfigurationService())->configureProperty($cid,($_POST['sem_mensalidade']??'')==='1',$bps,(int)Auth::user()['id'],(string)($_POST['motivo']??''));flash('success','Condicao comercial salva para novas obrigacoes e checkouts.');}catch(Throwable $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel salvar a condicao comercial.'));}
  $this->redirect('/admin/chacaras/'.$cid);
 }
 public function invoice(string $id):void
 {
  $p=Auth::requireProprietarioOperacional();verify_csrf();$oid=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$oid){http_response_code(404);return;}
  try{$o=(new MonthlyBillingService())->issue($oid,(int)$p['id'],(string)($_POST['forma_pagamento']??''));$url=$o['invoice_url']??'';if(!preg_match('#^https://([a-z0-9-]+\.)*asaas\.com(?:/|$)#i',$url))throw new RuntimeException('Fatura ainda nao disponivel.');header('Location: '.$url,true,303);exit;}catch(Throwable $e){flash('error',\App\Services\FinancialErrorMessage::publicMessage($e,'Nao foi possivel preparar a fatura.'));$this->redirect('/proprietario/mensalidades');}
 }
}
