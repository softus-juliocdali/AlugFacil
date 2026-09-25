<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;
use Throwable;

final class AffiliateIdentityService
{
    public function __construct(private ?PDO $db=null) {$this->db??=Database::getConnection();}

    public function vincular(int $affiliateId,string $affiliatePassword,string $userEmail,string $userPassword): int
    {
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM afiliados WHERE id=:a FOR UPDATE');$q->execute(['a'=>$affiliateId]);$a=$q->fetch();
            $q=$this->db->prepare('SELECT * FROM usuarios WHERE LOWER(email)=LOWER(:e) FOR UPDATE');$q->execute(['e'=>trim($userEmail)]);$u=$q->fetch();
            if(!$a||!$u||$a['status']!=='ativo'||$u['status']!=='ativo'||!password_verify($affiliatePassword,$a['senha_hash'])||!password_verify($userPassword,$u['senha_hash']))throw new DomainException('Nao foi possivel comprovar o acesso as duas contas.');
            if($a['usuario_id']!==null && (int)$a['usuario_id']!==(int)$u['id'])throw new DomainException('Afiliado ja vinculado a outra identidade. Procure o suporte.');
            $q=$this->db->prepare('SELECT id FROM afiliados WHERE usuario_id=:u AND id<>:a');$q->execute(['u'=>$u['id'],'a'=>$affiliateId]);
            if($q->fetchColumn()!==false)throw new DomainException('Identidade ja vinculada a outro afiliado.');
            if($a['usuario_id']===null) {
                $this->db->prepare('UPDATE afiliados SET usuario_id=:u WHERE id=:a')->execute(['u'=>$u['id'],'a'=>$affiliateId]);
                $this->db->prepare("INSERT INTO historico_vinculos_afiliados(afiliado_id,usuario_id,evento) VALUES(:a,:u,'vinculo_comprovado')")->execute(['a'=>$affiliateId,'u'=>$u['id']]);
            }
            $this->db->commit();return (int)$u['id'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function principal(int $affiliateId): ?array
    {
        $q=$this->db->prepare("SELECT u.id,u.nome,u.email,u.tipo_usuario,u.status FROM afiliados a JOIN usuarios u ON u.id=a.usuario_id WHERE a.id=:a AND a.status='ativo' AND u.status='ativo'");$q->execute(['a'=>$affiliateId]);return $q->fetch()?:null;
    }
}
