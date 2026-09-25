<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use PDO;use RuntimeException;use Throwable;
final class PayerDocumentService
{
 public function __construct(private ?PDO $db=null){$this->db??=Database::getConnection();}
 public function get(int $user):array{$q=$this->db->prepare('SELECT d.*,p.id proprietario_id,c.cpf_cnpj documento_proprietario FROM usuarios u LEFT JOIN usuario_documentos d ON d.usuario_id=u.id LEFT JOIN proprietarios p ON p.usuario_id=u.id LEFT JOIN proprietario_cadastro c ON c.proprietario_id=p.id WHERE u.id=:u');$q->execute(['u'=>$user]);return $q->fetch()?:[];}
 public function save(int $user,string $type,string $document):void
 {
  $document=preg_replace('/\D/','',$document);$type=strtoupper(trim($type));if(!AsaasSubcontaService::documentoValido($document,$type))throw new RuntimeException('Informe CPF/CNPJ valido.');
  $this->db->beginTransaction();try{
   $q=$this->db->prepare("SELECT id FROM usuarios WHERE id=:u AND status='ativo' AND tipo_usuario IN ('cliente','admin') FOR UPDATE");$q->execute(['u'=>$user]);if(!$q->fetchColumn())throw new RuntimeException('Proprietarios devem usar Dados Cadastrais.');
   $old=$this->get($user);if(($old['cpf_cnpj']??null)===$document){$this->db->commit();return;}
   $q=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM asaas_customers WHERE usuario_id=:u) OR EXISTS(SELECT 1 FROM operacoes_financeiras WHERE tipo='customer' AND entidade=:e)");$q->execute(['u'=>$user,'e'=>'usuario:'.$user]);if($q->fetchColumn())throw new RuntimeException('Documento ja utilizado em operacao financeira. Solicite conciliacao antes de altera-lo.');
   $this->db->prepare('INSERT INTO usuario_documentos(usuario_id,tipo_pessoa,cpf_cnpj) VALUES(:u,:t,:d) ON CONFLICT(usuario_id) DO UPDATE SET tipo_pessoa=EXCLUDED.tipo_pessoa,cpf_cnpj=EXCLUDED.cpf_cnpj,atualizado_em=clock_timestamp()')->execute(['u'=>$user,'t'=>$type,'d'=>$document]);
   $this->db->prepare('INSERT INTO historico_documentos_pagador(usuario_id,documento_anterior,documento_novo) VALUES(:u,:a,:n)')->execute(['u'=>$user,'a'=>$old['cpf_cnpj']??null,'n'=>$document]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
