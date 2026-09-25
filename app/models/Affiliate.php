<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

final class Affiliate extends Model
{
    public function listAdministrative(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['nome', 'codigo', 'cpf_cnpj', 'email'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = $field === 'cpf_cnpj'
                    ? "a.cpf_cnpj LIKE :{$field}"
                    : "a.{$field} ILIKE :{$field}";
                $params[$field] = '%' . $value . '%';
            }
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['ativo', 'bloqueado'], true)) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $statement = $this->db->prepare(
            "SELECT id, codigo, nome, cpf_cnpj, telefone, email, status, criado_em, percentual_comissao_bps
             FROM afiliados a {$clause} ORDER BY criado_em DESC, id DESC"
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, codigo, nome, cpf_cnpj, telefone, email, chave_pix, tipo_chave_pix,
                    banco, observacoes, status, criado_em, atualizado_em, percentual_comissao_bps
             FROM afiliados WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, codigo, nome, email, senha_hash, status
             FROM afiliados WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $statement->execute(['email' => trim($email)]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findWithPasswordById(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT id,codigo,nome,email,status,senha_hash FROM afiliados WHERE id=:id LIMIT 1');$statement->execute(['id'=>$id]);return$statement->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function updateOwnProfile(int$id,array$data,?string$newPassword=null):void
    {
        $passwordSql=$newPassword!==null?',senha_hash=:senha_hash':'';$params=['id'=>$id,'nome'=>$data['nome'],'telefone'=>$data['telefone'],'email'=>$data['email'],'chave_pix'=>$data['chave_pix'],'tipo_chave_pix'=>$data['tipo_chave_pix'],'banco'=>$data['banco']?:null];if($newPassword!==null)$params['senha_hash']=password_hash($newPassword,PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE afiliados SET nome=:nome,telefone=:telefone,email=:email,chave_pix=:chave_pix,tipo_chave_pix=:tipo_chave_pix,banco=:banco$passwordSql WHERE id=:id")->execute($params);
    }

    public function findActiveByCode(string $code): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, codigo FROM afiliados
             WHERE codigo = :codigo AND status = 'ativo' LIMIT 1"
        );
        $statement->execute(['codigo' => strtoupper(trim($code))]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function credentialsMatch(?array $affiliate, string $password): bool
    {
        return $affiliate !== null
            && $password !== ''
            && isset($affiliate['senha_hash'])
            && password_verify($password, (string) $affiliate['senha_hash']);
    }

    public static function canAuthenticate(?array $affiliate, string $password): bool
    {
        return self::credentialsMatch($affiliate, $password)
            && ($affiliate['status'] ?? null) === 'ativo';
    }

    public function identityExists(string $email, string $document, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM afiliados WHERE (LOWER(email) = LOWER(:email) OR cpf_cnpj = :document)';
        $params = ['email' => $email, 'document' => $document];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    public function create(array $data): array
    {
        $statement = $this->db->prepare(
            'INSERT INTO afiliados
                (nome, cpf_cnpj, telefone, email, senha_hash, chave_pix, tipo_chave_pix, banco, observacoes, status)
             VALUES
                (:nome, :cpf_cnpj, :telefone, :email, :senha_hash, :chave_pix, :tipo_chave_pix, :banco, :observacoes, :status)
             RETURNING id, codigo'
        );
        $statement->execute($this->persistenceData($data, true));
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function update(int $id, array $data): bool
    {
        $passwordSql = '';
        $params = $this->persistenceData($data, false);
        if (($data['senha'] ?? '') !== '') {
            $passwordSql = ', senha_hash = :senha_hash';
            $params['senha_hash'] = password_hash((string) $data['senha'], PASSWORD_DEFAULT);
        }
        $params['id'] = $id;
        $statement = $this->db->prepare(
            "UPDATE afiliados SET nome=:nome, cpf_cnpj=:cpf_cnpj, telefone=:telefone, email=:email,
                chave_pix=:chave_pix, tipo_chave_pix=:tipo_chave_pix, banco=:banco,
                observacoes=:observacoes, status=:status {$passwordSql} WHERE id=:id"
        );
        $statement->execute($params);
        return $statement->rowCount() === 1;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $statement = $this->db->prepare(
            "UPDATE afiliados SET status=:status WHERE id=:id AND status<>:status"
        );
        $statement->execute(['id' => $id, 'status' => $status]);
        return $statement->rowCount() === 1;
    }

    private function persistenceData(array $data, bool $withPassword): array
    {
        $params = [
            'nome' => $data['nome'], 'cpf_cnpj' => $data['cpf_cnpj'], 'telefone' => $data['telefone'],
            'email' => $data['email'], 'chave_pix' => $data['chave_pix'],
            'tipo_chave_pix' => $data['tipo_chave_pix'], 'banco' => $data['banco'] ?: null,
            'observacoes' => $data['observacoes'] ?: null, 'status' => $data['status'],
        ];
        if ($withPassword) {
            $params['senha_hash'] = password_hash((string) $data['senha'], PASSWORD_DEFAULT);
        }
        return $params;
    }
}
