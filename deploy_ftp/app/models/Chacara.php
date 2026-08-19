<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\ReservaStatusService;
use DateTimeImmutable;
use PDO;
use Throwable;

final class Chacara extends Model
{
    public static function clausulaElegibilidadePublica(string $alias='c',string $aliasProprietario='p',string $aliasUsuario='u'): string
    {
        foreach([$alias,$aliasProprietario,$aliasUsuario] as $sqlAlias)if(!preg_match('/^[a-z][a-z0-9_]*$/i',$sqlAlias))throw new \InvalidArgumentException('Alias SQL invalido.');
        return "$alias.status_aprovacao = 'aprovada' AND $alias.status_operacional = 'disponivel' AND $aliasUsuario.status = 'ativo' AND NOT EXISTS (SELECT 1 FROM mensalidades_anuncios ma_publica WHERE ma_publica.chacara_id=$alias.id AND ma_publica.ativa=TRUE AND ma_publica.status<>'EM_DIA')";
    }

    public static function elegivelPublicamente(array $chacara,?array $mensalidade=null):bool
    {
        $base=($chacara['status_aprovacao']??null)==='aprovada'&&($chacara['status_operacional']??null)==='disponivel'&&($chacara['usuario_status']??null)==='ativo';
        if(!$base)return false;
        $ativa=($mensalidade['ativa']??false)===true||in_array($mensalidade['ativa']??null,[1,'1','t','true'],true);
        return !$ativa||($mensalidade['status']??null)==='EM_DIA';
    }

    public function buscarPerfil(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT c.* FROM chacaras c
             INNER JOIN proprietarios p ON p.id = c.proprietario_id
             INNER JOIN usuarios u ON u.id = p.usuario_id
             WHERE c.id = :id
               AND " . self::clausulaElegibilidadePublica() . "
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $chacara = $statement->fetch();

        return $chacara ?: null;
    }

    public function buscarFotos(int $chacaraId): array
    {
        $statement = $this->db->prepare(
            "SELECT caminho_foto, principal, ordem
             FROM chacara_fotos
             WHERE chacara_id = :chacara_id
             ORDER BY (principal = 'sim') DESC, ordem ASC, id ASC"
        );
        $statement->execute(['chacara_id' => $chacaraId]);
        return $statement->fetchAll();
    }

    public function buscarAvaliacoesAtivas(int $chacaraId): array
    {
        $statement = $this->db->prepare(
            "SELECT a.nota, a.comentario, a.data_avaliacao, u.nome AS usuario_nome
             FROM avaliacoes a
             INNER JOIN usuarios u ON u.id = a.usuario_id
             WHERE a.chacara_id = :chacara_id AND a.status = 'ativo'
             ORDER BY a.data_avaliacao DESC, a.id DESC"
        );
        $statement->execute(['chacara_id' => $chacaraId]);
        return $statement->fetchAll();
    }

    public function totalPorProprietario(int $proprietarioId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM chacaras WHERE proprietario_id = :proprietario_id'
        );
        $statement->execute(['proprietario_id' => $proprietarioId]);

        return (int) $statement->fetchColumn();
    }

    public function resumoAdministrativo(): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total_chacaras,
                    COUNT(*) FILTER (WHERE status = 'disponivel') AS total_disponiveis,
                    COUNT(DISTINCT c.id) FILTER (WHERE r.id IS NOT NULL) AS total_locadas
                FROM chacaras c
                LEFT JOIN reservas r ON r.chacara_id = c.id
                    AND r.status_reserva IN ('pagamento_confirmado', 'confirmada')
                    AND CURRENT_DATE >= r.data_inicio
                    AND CURRENT_DATE < r.data_fim
                SQL
        );
        $statement->execute();
        $resumo = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_chacaras' => (int) ($resumo['total_chacaras'] ?? 0),
            'total_disponiveis' => (int) ($resumo['total_disponiveis'] ?? 0),
            'total_locadas' => (int) ($resumo['total_locadas'] ?? 0),
        ];
    }

    public function listarPorProprietario(int $proprietarioId): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    c.id,
                    c.nome,
                    c.descricao,
                    c.tipo_imovel,
                    c.valor_diaria,
                    c.cidade,
                    c.estado,
                    c.regiao,
                    c.endereco,
                    c.latitude,
                    c.longitude,
                    c.status,
                    c.status_aprovacao,
                    c.status_operacional,
                    c.motivo_status,
                    c.foto_principal,
                    c.data_atualizacao,
                    COALESCE(m.status,'SEM_MENSALIDADE') AS mensalidade_status,
                    m.valor_centavos AS mensalidade_valor_centavos,
                    (
                        SELECT COUNT(*)
                        FROM chacara_fotos cf
                        WHERE cf.chacara_id = c.id
                    ) AS total_fotos
                FROM chacaras c
                LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id
                WHERE c.proprietario_id = :proprietario_id
                ORDER BY c.data_cadastro DESC, c.id DESC
                SQL
        );
        $statement->execute(['proprietario_id' => $proprietarioId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarDoProprietario(int $id, int $proprietarioId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM chacaras
             WHERE id = :id AND proprietario_id = :proprietario_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'proprietario_id' => $proprietarioId,
        ]);
        $chacara = $statement->fetch(PDO::FETCH_ASSOC);

        return $chacara ?: null;
    }

    public function criarParaProprietario(int $proprietarioId, array $dados): int
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO chacaras
                    (proprietario_id, nome, descricao, tipo_imovel, valor_diaria, cidade, estado, regiao,
                     endereco, latitude, longitude, checkin_hora_inicial,checkin_hora_final,checkout_hora_inicial,checkout_hora_final,status, status_aprovacao, status_operacional)
                VALUES
                    (:proprietario_id, :nome, :descricao, :tipo_imovel, :valor_diaria, :cidade, :estado, :regiao,
                      :endereco, :latitude, :longitude,:ci,:cf,:coi,:cof,'pendente', 'pendente', 'indisponivel')
                RETURNING id
                SQL
        );
        $statement->execute([
            'proprietario_id' => $proprietarioId,
            'nome' => $dados['nome'],
            'descricao' => $dados['descricao'] ?: null,
            'tipo_imovel' => $dados['tipo_imovel'],
            'valor_diaria' => $dados['valor_diaria'],
            'cidade' => $dados['cidade'],
            'estado' => $dados['estado'] ?? null,
            'regiao' => $dados['regiao'] ?: null,
            'endereco' => $dados['endereco'],
            'latitude' => $dados['latitude'],
            'longitude' => $dados['longitude'],
            'ci'=>$dados['checkin_hora_inicial'],'cf'=>$dados['checkin_hora_final'],'coi'=>$dados['checkout_hora_inicial'],'cof'=>$dados['checkout_hora_final'],
        ]);

        return (int) $statement->fetchColumn();
    }

    public function atualizarDoProprietario(int $id, int $proprietarioId, array $dados): bool
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE chacaras
                SET nome = :nome,
                    descricao = :descricao,
                    tipo_imovel = :tipo_imovel,
                    valor_diaria = :valor_diaria,
                    cidade = :cidade,
                    estado = :estado,
                    regiao = :regiao,
                    endereco = :endereco,
                    latitude = :latitude,
                    longitude = :longitude
                    ,checkin_hora_inicial=:ci,checkin_hora_final=:cf,checkout_hora_inicial=:coi,checkout_hora_final=:cof
                WHERE id = :id AND proprietario_id = :proprietario_id
                SQL
        );
        $statement->execute([
            'id' => $id,
            'proprietario_id' => $proprietarioId,
            'nome' => $dados['nome'],
            'descricao' => $dados['descricao'] ?: null,
            'tipo_imovel' => $dados['tipo_imovel'],
            'valor_diaria' => $dados['valor_diaria'],
            'cidade' => $dados['cidade'],
            'estado' => $dados['estado'] ?? null,
            'regiao' => $dados['regiao'] ?: null,
            'endereco' => $dados['endereco'],
            'latitude' => $dados['latitude'],
            'longitude' => $dados['longitude'],
            'ci'=>$dados['checkin_hora_inicial'],'cf'=>$dados['checkin_hora_final'],'coi'=>$dados['checkout_hora_inicial'],'cof'=>$dados['checkout_hora_final'],
        ]);

        return $statement->rowCount() > 0;
    }

    public function atualizarStatusDoProprietario(int $id, int $proprietarioId, string $status): bool
    {
        if (!in_array($status, ['disponivel', 'indisponivel'], true)) {
            return false;
        }
        $statement = $this->db->prepare(
            'UPDATE chacaras
             SET status_operacional = :status,
                 status = :status
             WHERE id = :id AND proprietario_id = :proprietario_id
               AND status_aprovacao = \'aprovada\''
        );
        $statement->execute([
            'id' => $id,
            'proprietario_id' => $proprietarioId,
            'status' => $status,
        ]);

        return $statement->rowCount() > 0;
    }

    public function desativarDoProprietario(int $id, int $proprietarioId): bool
    {
        return $this->atualizarStatusDoProprietario($id, $proprietarioId, 'indisponivel');
    }

    public function buscarFotosGerenciamento(int $chacaraId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, caminho_foto, principal, ordem, data_cadastro
             FROM chacara_fotos
             WHERE chacara_id = :chacara_id
             ORDER BY (principal = \'sim\') DESC, ordem ASC, id ASC'
        );
        $statement->execute(['chacara_id' => $chacaraId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adicionarFoto(int $chacaraId, string $caminho): int
    {
        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'SELECT COALESCE(MAX(ordem), 0) + 1
                 FROM chacara_fotos
                 WHERE chacara_id = :chacara_id'
            );
            $statement->execute(['chacara_id' => $chacaraId]);
            $ordem = (int) $statement->fetchColumn();

            $principal = $this->temFotoPrincipal($chacaraId) ? 'nao' : 'sim';
            $insert = $this->db->prepare(
                'INSERT INTO chacara_fotos (chacara_id, caminho_foto, principal, ordem)
                 VALUES (:chacara_id, :caminho_foto, :principal, :ordem)
                 RETURNING id'
            );
            $insert->execute([
                'chacara_id' => $chacaraId,
                'caminho_foto' => $caminho,
                'principal' => $principal,
                'ordem' => $ordem,
            ]);
            $fotoId = (int) $insert->fetchColumn();

            if ($principal === 'sim') {
                $this->atualizarFotoPrincipalCampo($chacaraId, $caminho);
            }

            $this->db->commit();
            return $fotoId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function definirFotoPrincipal(int $chacaraId, int $fotoId): ?string
    {
        $foto = $this->buscarFoto($chacaraId, $fotoId);

        if ($foto === null) {
            return null;
        }

        $this->db->beginTransaction();

        try {
            $this->db->prepare('UPDATE chacara_fotos SET principal = \'nao\' WHERE chacara_id = :chacara_id')
                ->execute(['chacara_id' => $chacaraId]);
            $this->db->prepare('UPDATE chacara_fotos SET principal = \'sim\' WHERE id = :id AND chacara_id = :chacara_id')
                ->execute(['id' => $fotoId, 'chacara_id' => $chacaraId]);
            $this->atualizarFotoPrincipalCampo($chacaraId, $foto['caminho_foto']);
            $this->db->commit();

            return $foto['caminho_foto'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function removerFoto(int $chacaraId, int $fotoId): ?string
    {
        $foto = $this->buscarFoto($chacaraId, $fotoId);

        if ($foto === null) {
            return null;
        }

        $this->db->beginTransaction();

        try {
            $this->db->prepare('DELETE FROM chacara_fotos WHERE id = :id AND chacara_id = :chacara_id')
                ->execute(['id' => $fotoId, 'chacara_id' => $chacaraId]);

            if ($foto['principal'] === 'sim') {
                $proxima = $this->primeiraFoto($chacaraId);

                if ($proxima !== null) {
                    $this->db->prepare('UPDATE chacara_fotos SET principal = \'sim\' WHERE id = :id')
                        ->execute(['id' => $proxima['id']]);
                    $this->atualizarFotoPrincipalCampo($chacaraId, $proxima['caminho_foto']);
                } else {
                    $this->atualizarFotoPrincipalCampo($chacaraId, null);
                }
            }

            $this->db->commit();
            return $foto['caminho_foto'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function listarDisponibilidadeResumoPorProprietario(int $proprietarioId, int $limite = 8): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    d.data,
                    d.status,
                    d.observacao,
                    c.nome AS chacara_nome,
                    c.cidade
                FROM disponibilidades d
                INNER JOIN chacaras c ON c.id = d.chacara_id
                WHERE c.proprietario_id = :proprietario_id
                  AND d.data >= CURRENT_DATE
                ORDER BY d.data ASC, c.nome ASC
                LIMIT :limite
                SQL
        );
        $statement->bindValue(':proprietario_id', $proprietarioId, PDO::PARAM_INT);
        $statement->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarDatasIndisponiveis(int $chacaraId, string $inicio, string $fim): array
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT data::text, status
                FROM disponibilidades
                WHERE chacara_id = :chacara_id
                  AND data BETWEEN :inicio AND :fim
                  AND status IN ('reservado', 'bloqueado')
                UNION
                SELECT serie.data::date::text AS data, 'reservado' AS status
                FROM reservas r
                CROSS JOIN LATERAL generate_series(
                    r.data_inicio::timestamp,
                    (r.data_fim - 1)::timestamp,
                    interval '1 day'
                ) AS serie(data)
                WHERE r.chacara_id = :reserva_chacara_id
                  AND r.status_reserva IN (%s)
                  AND (r.status_reserva <> 'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em > CURRENT_TIMESTAMP)
                  AND serie.data::date BETWEEN :reserva_inicio AND :reserva_fim
                ORDER BY data
                SQL
        , ReservaStatusService::listaSqlBloqueiamDatas()));
        $statement->execute([
            'chacara_id' => $chacaraId,
            'inicio' => $inicio,
            'fim' => $fim,
            'reserva_chacara_id' => $chacaraId,
            'reserva_inicio' => $inicio,
            'reserva_fim' => $fim,
        ]);
        return $statement->fetchAll();
    }

    public function buscarCalendarioProprietario(int $chacaraId, int $proprietarioId, string $inicio, string $fim): array
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT
                    d.data::text,
                    d.status,
                    d.observacao,
                    NULL::integer AS reserva_id
                FROM disponibilidades d
                INNER JOIN chacaras c ON c.id = d.chacara_id
                WHERE d.chacara_id = :chacara_id
                  AND c.proprietario_id = :proprietario_id
                  AND d.data BETWEEN :inicio AND :fim
                UNION ALL
                SELECT
                    serie.data::date::text AS data,
                    'reservado' AS status,
                    'Reserva confirmada' AS observacao,
                    r.id AS reserva_id
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                CROSS JOIN LATERAL generate_series(
                    r.data_inicio::timestamp,
                    (r.data_fim - 1)::timestamp,
                    interval '1 day'
                ) AS serie(data)
                WHERE r.chacara_id = :reserva_chacara_id
                  AND c.proprietario_id = :reserva_proprietario_id
                  AND r.status_reserva IN (%s)
                  AND (r.status_reserva <> 'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em > CURRENT_TIMESTAMP)
                  AND serie.data::date BETWEEN :reserva_inicio AND :reserva_fim
                ORDER BY data ASC, status DESC
                SQL
        , ReservaStatusService::listaSqlBloqueiamDatas()));
        $statement->execute([
            'chacara_id' => $chacaraId,
            'proprietario_id' => $proprietarioId,
            'inicio' => $inicio,
            'fim' => $fim,
            'reserva_chacara_id' => $chacaraId,
            'reserva_proprietario_id' => $proprietarioId,
            'reserva_inicio' => $inicio,
            'reserva_fim' => $fim,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existeReservaConfirmadaNoPeriodo(int $chacaraId, int $proprietarioId, string $inicio, string $fim): bool
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM reservas r
                    INNER JOIN chacaras c ON c.id = r.chacara_id
                    WHERE r.chacara_id = :chacara_id
                      AND c.proprietario_id = :proprietario_id
                      AND r.status_reserva IN (%s)
                      AND (r.status_reserva <> 'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em > CURRENT_TIMESTAMP)
                      AND r.data_inicio < :fim
                      AND r.data_fim > :inicio
                )
                SQL
        , ReservaStatusService::listaSqlBloqueiamDatas()));
        $statement->execute([
            'chacara_id' => $chacaraId,
            'proprietario_id' => $proprietarioId,
            'inicio' => $inicio,
            'fim' => $fim,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function bloquearPeriodo(int $chacaraId, int $proprietarioId, string $inicio, string $fim, string $observacao): int
    {
        $this->buscarDoProprietario($chacaraId, $proprietarioId) ?? throw new \RuntimeException('Chacara nao encontrada.');

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO disponibilidades (chacara_id, data, status, observacao)
                SELECT :chacara_id, serie.data::date, 'bloqueado', :observacao
                FROM generate_series(:inicio::date, :fim::date, interval '1 day') AS serie(data)
                ON CONFLICT (chacara_id, data)
                DO UPDATE SET status = 'bloqueado', observacao = EXCLUDED.observacao
                WHERE disponibilidades.status <> 'reservado'
                SQL
        );
        $statement->execute([
            'chacara_id' => $chacaraId,
            'inicio' => $inicio,
            'fim' => $fim,
            'observacao' => $observacao ?: null,
        ]);

        return $statement->rowCount();
    }

    public function liberarPeriodoBloqueado(int $chacaraId, int $proprietarioId, string $inicio, string $fim): int
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                DELETE FROM disponibilidades d
                USING chacaras c
                WHERE c.id = d.chacara_id
                  AND d.chacara_id = :chacara_id
                  AND c.proprietario_id = :proprietario_id
                  AND d.status = 'bloqueado'
                  AND d.data BETWEEN :inicio AND :fim
                SQL
        );
        $statement->execute([
            'chacara_id' => $chacaraId,
            'proprietario_id' => $proprietarioId,
            'inicio' => $inicio,
            'fim' => $fim,
        ]);

        return $statement->rowCount();
    }

    public function reservaFinalizadaDisponivelParaAvaliacao(int $chacaraId, int $usuarioId): ?int
    {
        $statement = $this->db->prepare(
            "SELECT r.id
             FROM reservas r
             LEFT JOIN avaliacoes a ON a.reserva_id = r.id
             WHERE r.chacara_id = :chacara_id
               AND r.usuario_id = :usuario_id
               AND r.status_reserva = 'finalizada'
               AND a.id IS NULL
             ORDER BY r.data_fim DESC
             LIMIT 1"
        );
        $statement->execute(['chacara_id' => $chacaraId, 'usuario_id' => $usuarioId]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function temFotoPrincipal(int $chacaraId): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM chacara_fotos
                WHERE chacara_id = :chacara_id AND principal = \'sim\'
            )'
        );
        $statement->execute(['chacara_id' => $chacaraId]);

        return (bool) $statement->fetchColumn();
    }

    private function buscarFoto(int $chacaraId, int $fotoId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, caminho_foto, principal, ordem
             FROM chacara_fotos
             WHERE id = :id AND chacara_id = :chacara_id
             LIMIT 1'
        );
        $statement->execute(['id' => $fotoId, 'chacara_id' => $chacaraId]);
        $foto = $statement->fetch(PDO::FETCH_ASSOC);

        return $foto ?: null;
    }

    private function primeiraFoto(int $chacaraId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, caminho_foto
             FROM chacara_fotos
             WHERE chacara_id = :chacara_id
             ORDER BY ordem ASC, id ASC
             LIMIT 1'
        );
        $statement->execute(['chacara_id' => $chacaraId]);
        $foto = $statement->fetch(PDO::FETCH_ASSOC);

        return $foto ?: null;
    }

    private function atualizarFotoPrincipalCampo(int $chacaraId, ?string $caminho): void
    {
        $this->db->prepare(
            'UPDATE chacaras SET foto_principal = :foto_principal WHERE id = :id'
        )->execute([
            'id' => $chacaraId,
            'foto_principal' => $caminho,
        ]);
    }

    public function criarAvaliacao(int $usuarioId, int $chacaraId, int $reservaId, int $nota, string $comentario): void
    {
        $statement = $this->db->prepare(
            "INSERT INTO avaliacoes
                (usuario_id, chacara_id, reserva_id, nota, comentario, status)
             VALUES
                (:usuario_id, :chacara_id, :reserva_id, :nota, :comentario, 'ativo')"
        );
        $statement->execute([
            'usuario_id' => $usuarioId,
            'chacara_id' => $chacaraId,
            'reserva_id' => $reservaId,
            'nota' => $nota,
            'comentario' => $comentario,
        ]);
    }

    public function favoritosDoUsuario(int $usuarioId): array
    {
        $statement = $this->db->prepare(
            'SELECT chacara_id
             FROM favoritos_chacaras
             WHERE usuario_id = :usuario_id'
        );
        $statement->execute(['usuario_id' => $usuarioId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function listarFavoritosDoUsuario(int $usuarioId): array
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT
                    c.id,
                    c.nome,
                    c.descricao,
                    c.tipo_imovel,
                    c.valor_diaria,
                    c.cidade,
                    c.regiao,
                    c.status,
                    f.data_cadastro AS data_favorito,
                    COALESCE(
                        (
                            SELECT cf.caminho_foto
                            FROM chacara_fotos cf
                            WHERE cf.chacara_id = c.id
                            ORDER BY (cf.principal = 'sim') DESC, cf.ordem ASC, cf.id ASC
                            LIMIT 1
                        ),
                        c.foto_principal
                    ) AS foto
                FROM favoritos_chacaras f
                INNER JOIN chacaras c ON c.id = f.chacara_id
                INNER JOIN proprietarios p ON p.id = c.proprietario_id
                INNER JOIN usuarios u ON u.id = p.usuario_id
                WHERE f.usuario_id = :usuario_id
                  AND %s
                ORDER BY f.data_cadastro DESC, c.nome ASC
                SQL
        , self::clausulaElegibilidadePublica()));
        $statement->execute(['usuario_id' => $usuarioId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usuarioFavoritou(int $usuarioId, int $chacaraId): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM favoritos_chacaras
                WHERE usuario_id = :usuario_id AND chacara_id = :chacara_id
            )'
        );
        $statement->execute([
            'usuario_id' => $usuarioId,
            'chacara_id' => $chacaraId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function alternarFavorito(int $usuarioId, int $chacaraId): bool
    {
        if ($this->usuarioFavoritou($usuarioId, $chacaraId)) {
            $statement = $this->db->prepare(
                'DELETE FROM favoritos_chacaras
                 WHERE usuario_id = :usuario_id AND chacara_id = :chacara_id'
            );
            $statement->execute([
                'usuario_id' => $usuarioId,
                'chacara_id' => $chacaraId,
            ]);

            return false;
        }

        if ($this->buscarPerfil($chacaraId) === null) {
            return false;
        }

        $statement = $this->db->prepare(
            'INSERT INTO favoritos_chacaras (usuario_id, chacara_id)
             VALUES (:usuario_id, :chacara_id)
             ON CONFLICT (usuario_id, chacara_id) DO NOTHING'
        );
        $statement->execute([
            'usuario_id' => $usuarioId,
            'chacara_id' => $chacaraId,
        ]);

        return true;
    }

    /**
     * @param array{
     *     valor_min?: float|null,
     *     valor_max?: float|null,
     *     tipo_imovel?: string,
     *     cidade?: string,
     *     regiao?: string,
     *     data_inicio?: string,
     *     data_fim?: string,
     *     ordenacao?: string
     * } $filtros
     */
    public function buscarDisponiveis(array $filtros = []): array
    {
        $condicoes = [
            self::clausulaElegibilidadePublica(),
        ];
        $parametros = [];

        if (($filtros['valor_min'] ?? null) !== null) {
            $condicoes[] = 'c.valor_diaria >= :valor_min';
            $parametros['valor_min'] = $filtros['valor_min'];
        }

        if (($filtros['valor_max'] ?? null) !== null) {
            $condicoes[] = 'c.valor_diaria <= :valor_max';
            $parametros['valor_max'] = $filtros['valor_max'];
        }

        if (($filtros['cidade'] ?? '') !== '') {
            $condicoes[] = 'c.cidade ILIKE :cidade';
            $parametros['cidade'] = '%' . $filtros['cidade'] . '%';
        }

        if (($filtros['regiao'] ?? '') !== '') {
            $condicoes[] = 'COALESCE(c.regiao, \'\') ILIKE :regiao';
            $parametros['regiao'] = '%' . $filtros['regiao'] . '%';
        }

        if (($filtros['tipo_imovel'] ?? '') !== '') {
            $condicoes[] = 'c.tipo_imovel = :tipo_imovel';
            $parametros['tipo_imovel'] = $filtros['tipo_imovel'];
        }

        $inicio = $filtros['data_inicio'] ?? '';
        $fim = $filtros['data_fim'] ?? '';

        if ($inicio !== '' && $fim !== '') {
            $condicoes[] = <<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM disponibilidades d
                    WHERE d.chacara_id = c.id
                      AND d.data >= :data_inicio
                      AND d.data < :data_fim
                      AND d.status IN ('reservado', 'bloqueado')
                )
                SQL;
            $condicoes[] = sprintf(<<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM reservas r
                    WHERE r.chacara_id = c.id
                      AND r.status_reserva IN (%s)
                      AND (r.status_reserva <> 'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em > CURRENT_TIMESTAMP)
                      AND r.data_inicio < :reserva_data_fim
                      AND r.data_fim > :reserva_data_inicio
                )
                SQL, ReservaStatusService::listaSqlBloqueiamDatas());
            $parametros['data_inicio'] = $inicio;
            $parametros['data_fim'] = $fim;
            $parametros['reserva_data_inicio'] = $inicio;
            $parametros['reserva_data_fim'] = $fim;
        }

        $ordenacoes = [
            'menor_preco' => 'c.valor_diaria ASC, c.nome ASC',
            'maior_preco' => 'c.valor_diaria DESC, c.nome ASC',
            'nome' => 'c.nome ASC',
            'recentes' => 'c.data_cadastro DESC, c.id DESC',
        ];
        $ordenacao = $ordenacoes[$filtros['ordenacao'] ?? ''] ?? 'c.data_cadastro DESC, c.id DESC';

        $sql = sprintf(
            <<<'SQL'
                SELECT
                    c.id,
                    c.nome,
                    c.descricao,
                    c.tipo_imovel,
                    c.valor_diaria,
                    c.cidade,
                    c.regiao,
                    c.foto_principal,
                    COALESCE(
                        (
                            SELECT cf.caminho_foto
                            FROM chacara_fotos cf
                            WHERE cf.chacara_id = c.id
                            ORDER BY (cf.principal = 'sim') DESC, cf.ordem ASC, cf.id ASC
                            LIMIT 1
                        ),
                        c.foto_principal
                    ) AS foto,
                    COALESCE(
                        (
                            SELECT ROUND(AVG(a.nota)::numeric, 1)
                            FROM avaliacoes a
                            WHERE a.chacara_id = c.id AND a.status = 'ativo'
                        ),
                        0
                    ) AS avaliacao,
                    (
                        SELECT COUNT(*)
                        FROM avaliacoes a
                        WHERE a.chacara_id = c.id AND a.status = 'ativo'
                    ) AS total_avaliacoes
                FROM chacaras c
                INNER JOIN proprietarios p ON p.id = c.proprietario_id
                INNER JOIN usuarios u ON u.id = p.usuario_id
                WHERE %s
                ORDER BY %s
                SQL,
            implode("\n AND ", $condicoes),
            $ordenacao
        );

        $statement = $this->db->prepare($sql);

        foreach ($parametros as $nome => $valor) {
            $tipo = is_float($valor) || is_int($valor) ? PDO::PARAM_STR : PDO::PARAM_STR;
            $statement->bindValue(':' . $nome, (string) $valor, $tipo);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function listarAdministrativo(string $status = ''): array
    {
        $where = $status !== '' ? 'WHERE c.status_aprovacao = :status' : '';
        $statement = $this->db->prepare(
            "SELECT c.id, c.nome, c.cidade, c.valor_diaria, c.status_aprovacao,
                    c.status_operacional, c.motivo_status, p.nome AS proprietario_nome,
                    p.status AS proprietario_status, u.status AS usuario_status,
                    COALESCE(m.status,'SEM_MENSALIDADE') AS mensalidade_status,m.valor_centavos AS mensalidade_valor_centavos
             FROM chacaras c INNER JOIN proprietarios p ON p.id = c.proprietario_id
             INNER JOIN usuarios u ON u.id = p.usuario_id LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id {$where}
             ORDER BY c.data_cadastro DESC, c.id DESC"
        );
        $statement->execute($status !== '' ? ['status' => $status] : []);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarAdministrativo(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, p.nome AS proprietario_nome, p.status AS proprietario_status,
                    u.status AS usuario_status FROM chacaras c
             INNER JOIN proprietarios p ON p.id = c.proprietario_id
             INNER JOIN usuarios u ON u.id = p.usuario_id WHERE c.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $chacara = $statement->fetch(PDO::FETCH_ASSOC);
        return $chacara ?: null;
    }

    public function atualizarStatusAdministrativo(int $id, string $status, ?string $motivo, int $administradorId): bool
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT c.status_aprovacao, p.status AS proprietario_status, u.status AS usuario_status
                 FROM chacaras c INNER JOIN proprietarios p ON p.id = c.proprietario_id
                 INNER JOIN usuarios u ON u.id = p.usuario_id WHERE c.id = :id FOR UPDATE OF c'
            );
            $statement->execute(['id' => $id]);
            $atual = $statement->fetch(PDO::FETCH_ASSOC);
            $transicoes = ['pendente' => ['aprovada', 'rejeitada'], 'aprovada' => ['bloqueada'],
                'bloqueada' => ['aprovada', 'pendente'], 'rejeitada' => ['pendente']];
            if (!$atual || !in_array($status, $transicoes[$atual['status_aprovacao']] ?? [], true)) {
                $this->db->rollBack();
                return false;
            }
            if ($status === 'aprovada' && $atual['usuario_status'] !== 'ativo') {
                $this->db->rollBack();
                return false;
            }
            $operacional = $status === 'aprovada' ? 'disponivel' : 'indisponivel';
            $legado = $status === 'aprovada' ? 'disponivel' : ($status === 'bloqueada' ? 'bloqueada' : 'pendente');
            $this->db->prepare(
                'UPDATE chacaras SET status_aprovacao = :status, status_operacional = :operacional,
                 status = :legado, motivo_status = :motivo, status_decidido_em = CURRENT_TIMESTAMP,
                 status_decidido_por = :administrador_id WHERE id = :id'
            )->execute(['id' => $id, 'status' => $status, 'operacional' => $operacional,
                'legado' => $legado, 'motivo' => $motivo ?: null, 'administrador_id' => $administradorId]);
            $this->db->prepare(
                'INSERT INTO historico_status_chacaras
                 (chacara_id, status_anterior, status_novo, motivo, administrador_id)
                 VALUES (:id, :anterior, :novo, :motivo, :administrador_id)'
            )->execute(['id' => $id, 'anterior' => $atual['status_aprovacao'], 'novo' => $status,
                'motivo' => $motivo ?: null, 'administrador_id' => $administradorId]);
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public static function periodoValido(string $inicio, string $fim): bool
    {
        if ($inicio === '' && $fim === '') {
            return true;
        }

        $dataInicio = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $dataFim = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);

        return $dataInicio !== false
            && $dataFim !== false
            && $dataInicio->format('Y-m-d') === $inicio
            && $dataFim->format('Y-m-d') === $fim
            && $dataFim > $dataInicio;
    }
}
