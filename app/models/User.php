<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;
use Throwable;

final class User extends Model
{
    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, nome, telefone, email, senha_hash, tipo_usuario, status
             FROM usuarios WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $statement->execute(['email' => trim($email)]);
        $user = $statement->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, nome, telefone, email, senha_hash, tipo_usuario, status
             FROM usuarios WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findOwnerByUserId(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, usuario_id, nome, telefone, email, cpf, status,
                    afiliado_id, afiliado_origem, afiliado_atribuido_em
             FROM proprietarios
             WHERE usuario_id = :usuario_id
             LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);
        $owner = $statement->fetch();

        return $owner ?: null;
    }

    public function resumoAdministrativo(): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    COUNT(*) FILTER (WHERE tipo_usuario = 'cliente') AS total_clientes,
                    (SELECT COUNT(*) FROM proprietarios) AS total_proprietarios
                FROM usuarios
                SQL
        );
        $statement->execute();
        $resumo = $statement->fetch() ?: [];

        return [
            'total_clientes' => (int) ($resumo['total_clientes'] ?? 0),
            'total_proprietarios' => (int) ($resumo['total_proprietarios'] ?? 0),
        ];
    }

    public function listarProprietariosAdministrativo(string $busca = ''): array
    {
        $where = '';
        $params = [];

        if ($busca !== '') {
            $where = 'WHERE p.nome ILIKE :busca OR p.telefone ILIKE :busca OR p.email ILIKE :busca';
            $params['busca'] = '%' . $busca . '%';
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    p.id,
                    p.usuario_id,
                    p.nome,
                    p.telefone,
                    p.email,
                    p.status,
                    p.motivo_status,
                    p.status_decidido_em,
                    p.status_decidido_por,
                    p.data_cadastro,
                    COUNT(c.id) AS total_chacaras
                FROM proprietarios p
                LEFT JOIN chacaras c ON c.proprietario_id = p.id
                {$where}
                GROUP BY p.id
                ORDER BY p.data_cadastro DESC, p.id DESC
                SQL
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarProprietarioAdministrativo(int $id): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    p.id,
                    p.usuario_id,
                    p.nome,
                    p.telefone,
                    p.email,
                    p.cpf,
                    p.status,
                    p.motivo_status,
                    p.status_decidido_em,
                    p.status_decidido_por,
                    p.data_cadastro,
                    p.data_atualizacao,
                    u.status AS usuario_status,
                    COUNT(c.id) AS total_chacaras
                FROM proprietarios p
                INNER JOIN usuarios u ON u.id = p.usuario_id
                LEFT JOIN chacaras c ON c.proprietario_id = p.id
                WHERE p.id = :id
                GROUP BY p.id, u.status
                LIMIT 1
                SQL
        );
        $statement->execute(['id' => $id]);
        $proprietario = $statement->fetch(PDO::FETCH_ASSOC);

        return $proprietario ?: null;
    }

    public function listarChacarasDoProprietarioAdministrativo(int $proprietarioId): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    c.id,
                    c.nome,
                    c.cidade,
                    c.regiao,
                    c.valor_diaria,
                    c.status,
                    c.status_aprovacao,
                    c.status_operacional,
                    c.data_cadastro,
                    COUNT(r.id) AS total_reservas
                FROM chacaras c
                LEFT JOIN reservas r ON r.chacara_id = c.id
                WHERE c.proprietario_id = :proprietario_id
                GROUP BY c.id
                ORDER BY c.data_cadastro DESC, c.id DESC
                SQL
        );
        $statement->execute(['proprietario_id' => $proprietarioId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function atualizarStatusProprietarioAdministrativo(int $proprietarioId, string $status, ?string $motivo, int $administradorId): bool
    {
        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'SELECT usuario_id, status FROM proprietarios WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $proprietarioId]);
            $atual = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$atual) {
                $this->db->rollBack();
                return false;
            }

            $transicoes = [
                'pendente' => ['ativo', 'rejeitado'],
                'ativo' => ['bloqueado'],
                'bloqueado' => ['ativo'],
                'rejeitado' => ['pendente'],
            ];
            if (!in_array($status, $transicoes[$atual['status']] ?? [], true)) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare(
                'UPDATE proprietarios
                 SET status = :status, motivo_status = :motivo,
                     status_decidido_em = CURRENT_TIMESTAMP, status_decidido_por = :administrador_id
                 WHERE id = :id'
            )->execute([
                'id' => $proprietarioId,
                'status' => $status,
                'motivo' => $motivo ?: null,
                'administrador_id' => $administradorId,
            ]);

            $this->db->prepare(
                'UPDATE usuarios SET status = :status WHERE id = :id AND tipo_usuario = \'proprietario\''
            )->execute([
                'id' => (int) $atual['usuario_id'],
                'status' => in_array($status, ['ativo', 'pendente', 'rejeitado'], true) ? 'ativo' : 'bloqueado',
            ]);

            $this->db->prepare(
                'INSERT INTO historico_status_proprietarios
                    (proprietario_id, status_anterior, status_novo, motivo, administrador_id)
                 VALUES (:id, :anterior, :novo, :motivo, :administrador_id)'
            )->execute([
                'id' => $proprietarioId,
                'anterior' => $atual['status'],
                'novo' => $status,
                'motivo' => $motivo ?: null,
                'administrador_id' => $administradorId,
            ]);

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function listarClientesAdministrativo(string $busca = ''): array
    {
        $where = "WHERE u.tipo_usuario = 'cliente'";
        $params = [];

        if ($busca !== '') {
            $where .= ' AND (u.nome ILIKE :busca OR u.telefone ILIKE :busca OR u.email ILIKE :busca)';
            $params['busca'] = '%' . $busca . '%';
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    u.id,
                    u.nome,
                    u.telefone,
                    u.email,
                    u.status,
                    u.data_cadastro,
                    COUNT(r.id) AS total_reservas
                FROM usuarios u
                LEFT JOIN reservas r ON r.usuario_id = u.id
                {$where}
                GROUP BY u.id
                ORDER BY u.data_cadastro DESC, u.id DESC
                SQL
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarClienteAdministrativo(int $id): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    u.id,
                    u.nome,
                    u.telefone,
                    u.email,
                    u.status,
                    u.data_cadastro,
                    u.data_atualizacao,
                    COUNT(r.id) AS total_reservas
                FROM usuarios u
                LEFT JOIN reservas r ON r.usuario_id = u.id
                WHERE u.id = :id AND u.tipo_usuario = 'cliente'
                GROUP BY u.id
                LIMIT 1
                SQL
        );
        $statement->execute(['id' => $id]);
        $usuario = $statement->fetch(PDO::FETCH_ASSOC);

        return $usuario ?: null;
    }

    public function listarReservasDoClienteAdministrativo(int $usuarioId): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.data_inicio,
                    r.data_fim,
                    r.valor_total,
                    r.status_reserva,
                    r.status_pagamento,
                    r.data_reserva,
                    c.nome AS chacara_nome,
                    p.nome AS proprietario_nome
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN proprietarios p ON p.id = r.proprietario_id
                WHERE r.usuario_id = :usuario_id
                ORDER BY r.data_reserva DESC, r.id DESC
                SQL
        );
        $statement->execute(['usuario_id' => $usuarioId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function atualizarStatusClienteAdministrativo(int $usuarioId, string $status): bool
    {
        $statement = $this->db->prepare(
            'UPDATE usuarios SET status = :status WHERE id = :id AND tipo_usuario = \'cliente\''
        );
        $statement->execute([
            'id' => $usuarioId,
            'status' => $status,
        ]);

        return $statement->rowCount() > 0;
    }

    public function listarAdministradores(string $busca = ''): array
    {
        $where = "WHERE tipo_usuario = 'admin'";
        $params = [];

        if ($busca !== '') {
            $where .= ' AND (nome ILIKE :busca OR telefone ILIKE :busca OR email ILIKE :busca)';
            $params['busca'] = '%' . $busca . '%';
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT id, nome, telefone, email, status, data_cadastro, data_atualizacao
                FROM usuarios
                {$where}
                ORDER BY data_cadastro DESC, id DESC
                SQL
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarAdministrador(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, nome, telefone, email, status, data_cadastro, data_atualizacao
             FROM usuarios
             WHERE id = :id AND tipo_usuario = 'admin'
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $admin = $statement->fetch(PDO::FETCH_ASSOC);

        return $admin ?: null;
    }

    public function criarAdministrador(array $data): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO usuarios (nome, telefone, email, senha_hash, tipo_usuario, status)
             VALUES (:nome, :telefone, :email, :senha_hash, 'admin', :status)
             RETURNING id"
        );
        $statement->execute([
            'nome' => $data['nome'],
            'telefone' => $data['telefone'] ?: null,
            'email' => $data['email'],
            'senha_hash' => password_hash($data['senha'], PASSWORD_DEFAULT),
            'status' => $data['status'],
        ]);

        return (int) $statement->fetchColumn();
    }

    public function atualizarAdministrador(int $id, array $data): bool
    {
        $fields = [
            'nome = :nome',
            'telefone = :telefone',
            'email = :email',
            'status = :status',
        ];
        $params = [
            'id' => $id,
            'nome' => $data['nome'],
            'telefone' => $data['telefone'] ?: null,
            'email' => $data['email'],
            'status' => $data['status'],
        ];

        if (!empty($data['senha'])) {
            $fields[] = 'senha_hash = :senha_hash';
            $params['senha_hash'] = password_hash($data['senha'], PASSWORD_DEFAULT);
        }

        $sql = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = :id AND tipo_usuario = 'admin'";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function atualizarSenhaAdministrador(int $id, string $senha): bool
    {
        $statement = $this->db->prepare(
            "UPDATE usuarios
             SET senha_hash = :senha_hash
             WHERE id = :id AND tipo_usuario = 'admin'"
        );
        $statement->execute([
            'id' => $id,
            'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
        ]);

        return $statement->rowCount() > 0;
    }

    public function totalAdministradoresAtivos(): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'admin' AND status = 'ativo'"
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function emailExistsForAnotherUser(string $email, int $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM usuarios
                WHERE LOWER(email) = LOWER(:email)
                  AND id <> :id
            )'
        );
        $statement->execute([
            'email' => trim($email),
            'id' => $userId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function cpfExistsForAnotherOwner(string $cpf, int $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM proprietarios
                WHERE cpf = :cpf
                  AND usuario_id <> :usuario_id
            )'
        );
        $statement->execute([
            'cpf' => preg_replace('/\D+/', '', $cpf),
            'usuario_id' => $userId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function updateClientData(int $userId, array $data): void
    {
        $fields = [
            'nome = :nome',
            'telefone = :telefone',
            'email = :email',
        ];
        $params = [
            'id' => $userId,
            'nome' => $data['nome'],
            'telefone' => $data['telefone'] ?: null,
            'email' => $data['email'],
        ];

        if (!empty($data['senha'])) {
            $fields[] = 'senha_hash = :senha_hash';
            $params['senha_hash'] = password_hash($data['senha'], PASSWORD_DEFAULT);
        }

        $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id AND tipo_usuario = \'cliente\'';
        $this->db->prepare($sql)->execute($params);
    }

    public function updateOwnerData(int $userId, array $data): void
    {
        $fields = [
            'nome = :nome',
            'telefone = :telefone',
            'email = :email',
        ];
        $params = [
            'id' => $userId,
            'nome' => $data['nome'],
            'telefone' => $data['telefone'] ?: null,
            'email' => $data['email'],
            'cpf' => preg_replace('/\D+/', '', (string) ($data['cpf'] ?? '')),
        ];

        if (!empty($data['senha'])) {
            $fields[] = 'senha_hash = :senha_hash';
            $params['senha_hash'] = password_hash($data['senha'], PASSWORD_DEFAULT);
        }

        $this->db->beginTransaction();
        try {
            $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id AND tipo_usuario = \'proprietario\'';
            $this->db->prepare($sql)->execute($params);

            $this->db->prepare(
                'UPDATE proprietarios
                 SET nome = :nome, telefone = :telefone, email = :email, cpf = :cpf
                 WHERE usuario_id = :usuario_id'
            )->execute([
                'usuario_id' => $userId,
                'nome' => $data['nome'],
                'telefone' => $data['telefone'] ?: null,
                'email' => $data['email'],
                'cpf' => $params['cpf'],
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createClient(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO usuarios (nome, telefone, email, senha_hash, tipo_usuario, status)
             VALUES (:nome, :telefone, :email, :senha_hash, :tipo_usuario, :status)
             RETURNING id'
        );
        $statement->execute([
            'nome' => $data['nome'],
            'telefone' => $data['telefone'] ?: null,
            'email' => $data['email'],
            'senha_hash' => password_hash($data['senha'], PASSWORD_DEFAULT),
            'tipo_usuario' => 'cliente',
            'status' => 'ativo',
        ]);
        return (int) $statement->fetchColumn();
    }

    public function createOwner(array $data, ?array $affiliateAttribution = null): int
    {
        $this->db->beginTransaction();
        try {
            $resolvedAttribution = null;
            $affiliateId = (int) ($affiliateAttribution['afiliado_id'] ?? 0);
            $origin = (string) ($affiliateAttribution['origem'] ?? '');
            if ($affiliateId > 0 && in_array($origin, ['link', 'codigo'], true)) {
                $activeAffiliate = $this->db->prepare(
                    "SELECT id FROM afiliados WHERE id = :id AND status = 'ativo' FOR SHARE"
                );
                $activeAffiliate->execute(['id' => $affiliateId]);
                if ($activeAffiliate->fetchColumn() !== false) {
                    $resolvedAttribution = [
                        'afiliado_id' => $affiliateId,
                        'origem' => $origin,
                        'atribuido_em' => (string) ($affiliateAttribution['atribuido_em'] ?? date(DATE_ATOM)),
                    ];
                }
            }

            $statement = $this->db->prepare(
                'INSERT INTO usuarios (nome, telefone, email, senha_hash, tipo_usuario, status)
                 VALUES (:nome, :telefone, :email, :senha_hash, :tipo_usuario, :status)
                 RETURNING id'
            );
            $statement->execute([
                'nome' => $data['nome'],
                'telefone' => $data['telefone'] ?: null,
                'email' => $data['email'],
                'senha_hash' => password_hash($data['senha'], PASSWORD_DEFAULT),
                'tipo_usuario' => 'proprietario',
                'status' => 'ativo',
            ]);
            $userId = (int) $statement->fetchColumn();

            $owner = $this->db->prepare(
                'INSERT INTO proprietarios
                    (usuario_id, nome, telefone, email, status, afiliado_id, afiliado_origem, afiliado_atribuido_em)
                 VALUES
                    (:usuario_id, :nome, :telefone, :email, :status, :afiliado_id, :afiliado_origem, :afiliado_atribuido_em)'
            );
            $owner->execute([
                'usuario_id' => $userId,
                'nome' => $data['nome'],
                'telefone' => $data['telefone'] ?: null,
                'email' => $data['email'],
                'status' => 'ativo',
                'afiliado_id' => $resolvedAttribution['afiliado_id'] ?? null,
                'afiliado_origem' => $resolvedAttribution['origem'] ?? null,
                'afiliado_atribuido_em' => $resolvedAttribution['atribuido_em'] ?? null,
            ]);
            $this->db->commit();
            return $userId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createPasswordReset(int $userId, string $token, string $expiresAt): void
    {
        $this->db->prepare('DELETE FROM password_resets WHERE usuario_id = :usuario_id')
            ->execute(['usuario_id' => $userId]);
        $statement = $this->db->prepare(
            'INSERT INTO password_resets (usuario_id, token_hash, expira_em)
             VALUES (:usuario_id, :token_hash, :expira_em)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expira_em' => $expiresAt,
        ]);
    }

    public function findValidPasswordReset(string $token): ?array
    {
        $statement = $this->db->prepare(
            'SELECT pr.id, pr.usuario_id, u.email
             FROM password_resets pr
             JOIN usuarios u ON u.id = pr.usuario_id
             WHERE pr.token_hash = :token_hash
               AND pr.usado_em IS NULL
               AND pr.expira_em > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $reset = $statement->fetch();
        return $reset ?: null;
    }

    public function resetPassword(int $resetId, int $userId, string $password): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE usuarios SET senha_hash = :senha_hash WHERE id = :id')
                ->execute([
                    'senha_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $userId,
                ]);
            $this->db->prepare(
                'UPDATE password_resets SET usado_em = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $resetId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
