<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use App\Services\ReservaStatusService;

final class Reserva extends Model
{
    public function buscarChacaraParaReserva(int $chacaraId): ?array
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT
                    c.id,
                    c.nome,
                    c.valor_diaria,
                    c.cidade,
                    c.regiao,
                    c.endereco,
                    c.foto_principal,
                    c.proprietario_id,
                    COALESCE(c.checkin_hora_inicial,TIME '14:00') AS checkin_hora_inicial,
                    COALESCE(c.checkin_hora_final,TIME '18:00') AS checkin_hora_final,
                    COALESCE(c.checkout_hora_inicial,TIME '08:00') AS checkout_hora_inicial,
                    COALESCE(c.checkout_hora_final,TIME '11:00') AS checkout_hora_final,
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
                FROM chacaras c
                INNER JOIN proprietarios p ON p.id = c.proprietario_id
                INNER JOIN usuarios pu ON pu.id = p.usuario_id
                WHERE c.id = :id
                  AND %s
                LIMIT 1
                SQL
        , Chacara::clausulaElegibilidadePublica('c','p','pu')));
        $statement->execute(['id' => $chacaraId]);
        $chacara = $statement->fetch();

        return $chacara ?: null;
    }

    public function existeIndisponibilidade(int $chacaraId, string $inicio, string $fim): bool
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM disponibilidades d
                    WHERE d.chacara_id = :chacara_id
                      AND d.data >= :inicio
                      AND d.data < :fim
                      AND d.status IN ('reservado', 'bloqueado')
                )
                SQL
        );
        $statement->execute([
            'chacara_id' => $chacaraId,
            'inicio' => $inicio,
            'fim' => $fim,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function existeConflitoReserva(int $chacaraId, string $inicio, string $fim): bool
    {
        $statement = $this->db->prepare(sprintf(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM reservas r
                    WHERE r.chacara_id = :chacara_id
                      AND r.data_inicio < :fim
                      AND r.data_fim > :inicio
                      AND r.status_reserva IN (%s)
                      AND (r.status_reserva <> 'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em > CURRENT_TIMESTAMP)
                )
                SQL
        , ReservaStatusService::listaSqlBloqueiamDatas()));
        $statement->execute([
            'chacara_id' => $chacaraId,
            'inicio' => $inicio,
            'fim' => $fim,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function criar(array $dados): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) $this->db->beginTransaction();

        try {
            $this->bloquearCriacaoConcorrente((int) $dados['chacara_id']);
            (new \App\Services\ReservationAuthorization($this->db))->assertCanBook((int)$dados['usuario_id'],(int)$dados['chacara_id']);
            (new ReservaStatusService($this->db))->expirarVencidas(25, (int) $dados['chacara_id']);

            if (($dados['schema_financeiro'] ?? 1) < 2 && $this->buscarChacaraParaReserva((int) $dados['chacara_id']) === null) {
                throw new RuntimeException('Imovel ou proprietario sem autorizacao para reserva.');
            }

            if (
                $this->existeIndisponibilidade((int) $dados['chacara_id'], (string) $dados['data_inicio'], (string) $dados['data_fim'])
                || $this->existeConflitoReserva((int) $dados['chacara_id'], (string) $dados['data_inicio'], (string) $dados['data_fim'])
            ) {
                throw new RuntimeException('Periodo indisponivel para reserva.');
            }

            $statement = $this->db->prepare(
                <<<'SQL'
                    INSERT INTO reservas
                        (
                            usuario_id,
                            proprietario_id,
                            chacara_id,
                            data_inicio,
                            data_fim,
                            quantidade_diarias,
                            valor_diaria,
                            valor_total,
                            status_reserva,
                            status_pagamento, expira_em, cotacao_id, schema_financeiro, modalidade,
                            valor_diaria_liquido_proprietario_centavos, valor_hospedagem_centavos,
                            valor_liquido_proprietario_centavos, taxa_plataforma_centavos,
                            taxa_gateway_estimada_centavos, valor_total_cliente_centavos,
                            plataforma_percentual_bps, plataforma_fixa_centavos, gateway_percentual_bps,
                            gateway_fixa_centavos, margem_seguranca_bps, forma_pagamento, quantidade_parcelas,
                            versao_precificacao, origem_precificacao, precificacao_detalhes, precificado_em
                            ,taxa_operacao_pix_snapshot_centavos,checkin_hora_inicial_snapshot,checkin_hora_final_snapshot,
                            checkout_hora_inicial_snapshot,checkout_hora_final_snapshot,checkin_inicio_em,
                            cancelamento_permitido_ate,repasse_liberavel_em,status_repasse,aceite_reserva_menos_24h
                        )
                    VALUES
                        (
                            :usuario_id,
                            :proprietario_id,
                            :chacara_id,
                            :data_inicio,
                            :data_fim,
                            :quantidade_diarias,
                            :valor_diaria,
                            :valor_total,
                            'aguardando_pagamento',
                            'pendente', COALESCE(CAST(:expira AS timestamptz),clock_timestamp() + INTERVAL '15 minutes'),:cotacao,:schema,:modalidade,
                            :vd_centavos,:hospedagem,:liquido,:plataforma,:gateway,:total_cliente,
                            :plataforma_bps,:plataforma_fixa,:gateway_bps,:gateway_fixa,:margem_bps,
                            :forma,:parcelas,:versao,:origem,CAST(:detalhes AS JSONB),CURRENT_TIMESTAMP
                            ,:taxa_operacao,:ci,:cf,:coi,:cof,
                            :checkin_inicio,:cancel_limit,:repasse_limit,
                            'aguardando_pagamento',:aceite_menos_24h
                        )
                    RETURNING id
                    SQL
            );
            $statement->execute([
                'usuario_id' => $dados['usuario_id'],
                'proprietario_id' => $dados['proprietario_id'],
                'chacara_id' => $dados['chacara_id'],
                'data_inicio' => $dados['data_inicio'],
                'data_fim' => $dados['data_fim'],
                'quantidade_diarias' => $dados['quantidade_diarias'],
                'valor_diaria' => $dados['valor_diaria'],
                'valor_total' => $dados['valor_total'],
                'vd_centavos'=>$dados['valor_diaria_liquido_proprietario_centavos'],'hospedagem'=>$dados['valor_hospedagem_centavos'],
                'liquido'=>$dados['valor_liquido_proprietario_centavos'],'plataforma'=>$dados['taxa_plataforma_centavos'],
                'gateway'=>$dados['taxa_gateway_estimada_centavos'],'total_cliente'=>$dados['valor_total_cliente_centavos'],
                'plataforma_bps'=>$dados['plataforma_percentual_bps'],'plataforma_fixa'=>$dados['plataforma_fixa_centavos'],
                'gateway_bps'=>$dados['gateway_percentual_bps'],'gateway_fixa'=>$dados['gateway_fixa_centavos'],
                'margem_bps'=>$dados['margem_seguranca_bps'],'forma'=>$dados['forma_pagamento'],'parcelas'=>$dados['quantidade_parcelas'],
                'versao'=>$dados['versao_precificacao'],'detalhes'=>json_encode($dados['precificacao_detalhes'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'expira'=>$dados['expira_em']??null,'cotacao'=>$dados['cotacao_id']??null,'schema'=>$dados['schema_financeiro']??1,'modalidade'=>$dados['modalidade']??'integral','origem'=>$dados['origem_precificacao']??'pix_operacao_v1',
                'taxa_operacao'=>$dados['taxa_operacao_pix_centavos'],'ci'=>$dados['checkin_hora_inicial_snapshot'],
                'cf'=>$dados['checkin_hora_final_snapshot'],'coi'=>$dados['checkout_hora_inicial_snapshot'],'cof'=>$dados['checkout_hora_final_snapshot'],
                'aceite_menos_24h'=>$dados['aceite_reserva_menos_24h'],
                'checkin_inicio'=>$dados['checkin_inicio_em'],'cancel_limit'=>$dados['cancelamento_permitido_ate'],'repasse_limit'=>$dados['repasse_liberavel_em'],
            ]);

            $id = (int) $statement->fetchColumn();
            $this->db->prepare("INSERT INTO repasses_reservas(reserva_id,proprietario_id,valor_reserva_centavos,valor_repasse_centavos,repasse_liberavel_em,status_local,external_reference) SELECT id,proprietario_id,valor_hospedagem_centavos,valor_liquido_proprietario_centavos,repasse_liberavel_em,'aguardando_pagamento','repasse_reserva_'||id FROM reservas WHERE id=:id ON CONFLICT(reserva_id) DO NOTHING")->execute(['id'=>$id]);
            (new ReservaStatusService($this->db))->registrarInicial($id);
            if ($ownTransaction) $this->db->commit();

            return ['id' => $id];
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function criarCotacao(int $usuarioId,int $chacaraId,string $inicio,string $fim,array $detalhes):string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);$id=substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
        $s=$this->db->prepare("INSERT INTO cotacoes_reserva(id,usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,forma_pagamento,quantidade_parcelas,detalhes,expira_em) VALUES(:id,:u,:c,:cfg,:v,:i,:f,:forma,:p,CAST(:d AS JSONB),CURRENT_TIMESTAMP+INTERVAL '15 minutes')");
        $s->execute(['id'=>$id,'u'=>$usuarioId,'c'=>$chacaraId,'cfg'=>$detalhes['configuracao_financeira_id'],'v'=>$detalhes['versao_precificacao'],'i'=>$inicio,'f'=>$fim,'forma'=>$detalhes['forma_pagamento'],'p'=>$detalhes['quantidade_parcelas'],'d'=>json_encode($detalhes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);return$id;
    }

    public function buscarCotacaoValida(string $id,int $usuarioId,int $chacaraId):array
    {return (new \App\Services\CheckoutQuoteService($this->db))->valid($id,$usuarioId,$chacaraId);}

    public function marcarCotacaoConsumida(string $id,int $usuarioId):void
    {throw new RuntimeException('Consuma a cotacao atomicamente com CheckoutQuoteService.');}

    public function buscarConfirmacao(int $reservaId, int $usuarioId): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.*,
                    c.nome AS chacara_nome,
                    c.cidade,
                    c.regiao,
                    c.endereco,
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
                    u.nome AS cliente_nome,
                    p.nome AS proprietario_nome
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN usuarios u ON u.id = r.usuario_id
                INNER JOIN proprietarios p ON p.id = r.proprietario_id
                WHERE r.id = :id
                  AND r.usuario_id = :usuario_id
                LIMIT 1
                SQL
        );
        $statement->execute([
            'id' => $reservaId,
            'usuario_id' => $usuarioId,
        ]);
        $reserva = $statement->fetch(PDO::FETCH_ASSOC);

        return $reserva ?: null;
    }

    public function buscarParaCobranca(int $reservaId): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.*,
                    c.nome AS chacara_nome,
                    u.id AS cliente_id,
                    u.nome AS cliente_nome,
                    u.email AS cliente_email,
                    u.telefone AS cliente_telefone
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN usuarios u ON u.id = r.usuario_id
                WHERE r.id = :id
                LIMIT 1
                SQL
        );
        $statement->execute(['id' => $reservaId]);
        $reserva = $statement->fetch(PDO::FETCH_ASSOC);

        return $reserva ?: null;
    }

    public function atualizarCobrancaAsaas(int $reservaId, string $idCobranca, string $linkPagamento, string $qrCode='', string $copiaCola='', string $status='PENDING', string $vencimento=''): void
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE reservas
                SET id_cobranca_asaas = :id_cobranca_asaas,
                    link_pagamento_asaas = :link_pagamento_asaas,
                    pix_qr_code_base64 = :qr, pix_copia_cola = :copia,
                    asaas_status = :status, asaas_vencimento = NULLIF(:vencimento,'')::date
                WHERE id = :id AND status_reserva = 'aguardando_pagamento'
                  AND status_pagamento = 'pendente' AND expira_em > CURRENT_TIMESTAMP
                  AND id_cobranca_asaas IS NULL
                SQL
        );
        $statement->execute([
            'id' => $reservaId,
            'id_cobranca_asaas' => $idCobranca,
            'link_pagamento_asaas' => $linkPagamento,
            'qr'=>$qrCode,'copia'=>$copiaCola,'status'=>$status,'vencimento'=>$vencimento,
        ]);
    }

    public function historico(int $reservaId): array
    {
        $s=$this->db->prepare('SELECT status_anterior,status_novo,motivo,origem,criado_em FROM historico_status_reservas WHERE reserva_id=:id ORDER BY criado_em DESC,id DESC');
        $s->execute(['id'=>$reservaId]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarDetalheProprietario(int $id, int $proprietarioId): ?array
    {
        $s=$this->db->prepare('SELECT r.*,c.nome AS chacara_nome,u.nome AS cliente_nome,u.email AS cliente_email,(SELECT status_local FROM repasses_reservas WHERE reserva_id=r.id) AS repasse_status,(SELECT valor_repasse_centavos FROM repasses_reservas WHERE reserva_id=r.id) AS valor_repasse_centavos FROM reservas r JOIN chacaras c ON c.id=r.chacara_id JOIN usuarios u ON u.id=r.usuario_id WHERE r.id=:id AND r.proprietario_id=:proprietario LIMIT 1');
        $s->execute(['id'=>$id,'proprietario'=>$proprietarioId]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function buscarDetalheAdministrativo(int $id): ?array
    {
        $s=$this->db->prepare('SELECT r.*,c.nome AS chacara_nome,u.nome AS cliente_nome,u.email AS cliente_email,p.nome AS proprietario_nome,(SELECT status_local FROM repasses_reservas WHERE reserva_id=r.id) AS repasse_status,(SELECT status_local FROM reembolsos_reservas WHERE reserva_id=r.id ORDER BY id DESC LIMIT 1) AS reembolso_status FROM reservas r JOIN chacaras c ON c.id=r.chacara_id JOIN usuarios u ON u.id=r.usuario_id JOIN proprietarios p ON p.id=r.proprietario_id WHERE r.id=:id LIMIT 1');
        $s->execute(['id'=>$id]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function listarAdministrativas(string $status=''): array
    {
        $where=$status!==''?'WHERE r.status_reserva=:status':'';$s=$this->db->prepare("SELECT r.id,r.data_inicio,r.data_fim,(r.valor_total_cliente_centavos/100.0) AS valor_total,r.status_reserva,r.status_pagamento,c.nome AS chacara_nome,u.nome AS cliente_nome,p.nome AS proprietario_nome FROM reservas r JOIN chacaras c ON c.id=r.chacara_id JOIN usuarios u ON u.id=r.usuario_id JOIN proprietarios p ON p.id=r.proprietario_id {$where} ORDER BY r.data_reserva DESC,r.id DESC");$s->execute($status!==''?['status'=>$status]:[]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarPorCliente(int $usuarioId): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.chacara_id,
                    r.data_inicio,
                    r.data_fim,
                    (r.valor_total_cliente_centavos / 100.0) AS valor_total,
                    r.status_reserva,
                    r.status_pagamento,
                    c.nome AS chacara_nome,
                    c.cidade,
                    c.regiao,
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
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                WHERE r.usuario_id = :usuario_id
                ORDER BY r.data_inicio DESC, r.id DESC
                SQL
        );
        $statement->execute(['usuario_id' => $usuarioId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resumoPorProprietario(int $proprietarioId): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total_reservas,
                    COALESCE(
                        (SUM(valor_liquido_proprietario_centavos) FILTER (WHERE status_pagamento = 'pago')) / 100.0,
                        0
                    ) AS total_faturamento,
                    COUNT(*) FILTER (WHERE status_reserva = 'confirmada') AS total_confirmadas,
                    COUNT(*) FILTER (
                        WHERE status_reserva IN (
                            'solicitada',
                            'aguardando_pagamento',
                            'pagamento_confirmado'
                        )
                    ) AS total_pendentes
                FROM reservas
                WHERE proprietario_id = :proprietario_id
                SQL
        );
        $statement->execute(['proprietario_id' => $proprietarioId]);
        $resumo = $statement->fetch(PDO::FETCH_ASSOC);

        return $resumo ?: [
            'total_reservas' => 0,
            'total_faturamento' => 0,
            'total_confirmadas' => 0,
            'total_pendentes' => 0,
        ];
    }

    public function resumoAdministrativo(): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total_reservas,
                    COALESCE((SUM(valor_total_cliente_centavos) FILTER (
                        WHERE status_pagamento = 'pago'
                           OR status_reserva IN ('pagamento_confirmado', 'confirmada')
                    )) / 100.0, 0) AS total_faturamento,
                    COUNT(*) FILTER (
                        WHERE status_reserva IN ('solicitada', 'aguardando_pagamento', 'pagamento_confirmado')
                    ) AS total_pendentes,
                    COUNT(*) FILTER (WHERE status_reserva = 'confirmada') AS total_confirmadas
                FROM reservas
                SQL
        );
        $statement->execute();
        $resumo = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_reservas' => (int) ($resumo['total_reservas'] ?? 0),
            'total_faturamento' => (float) ($resumo['total_faturamento'] ?? 0),
            'total_pendentes' => (int) ($resumo['total_pendentes'] ?? 0),
            'total_confirmadas' => (int) ($resumo['total_confirmadas'] ?? 0),
        ];
    }

    public function listarUltimasAdministrativas(int $limite = 6): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.data_inicio,
                    r.data_fim,
                    (r.valor_total_cliente_centavos / 100.0) AS valor_total,
                    r.status_reserva,
                    r.status_pagamento,
                    r.data_reserva,
                    c.nome AS chacara_nome,
                    u.nome AS cliente_nome,
                    p.nome AS proprietario_nome
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN usuarios u ON u.id = r.usuario_id
                INNER JOIN proprietarios p ON p.id = r.proprietario_id
                ORDER BY r.data_reserva DESC, r.id DESC
                LIMIT :limite
                SQL
        );
        $statement->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarUltimasPorProprietario(int $proprietarioId, int $limite = 5): array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.data_inicio,
                    r.data_fim,
                    (r.valor_liquido_proprietario_centavos / 100.0) AS valor_total,
                    r.status_reserva,
                    r.status_pagamento,
                    r.data_reserva,
                    c.nome AS chacara_nome,
                    c.cidade,
                    c.regiao,
                    u.nome AS cliente_nome
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN usuarios u ON u.id = r.usuario_id
                WHERE r.proprietario_id = :proprietario_id
                ORDER BY r.data_reserva DESC, r.id DESC
                LIMIT :limite
                SQL
        );
        $statement->bindValue(':proprietario_id', $proprietarioId, PDO::PARAM_INT);
        $statement->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function faturamentoPorProprietario(int $proprietarioId, string $inicio, string $fim): array
    {
        $params = [
            'proprietario_id' => $proprietarioId,
            'inicio' => $inicio,
            'fim' => $fim,
        ];

        $summary = $this->db->prepare(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total_reservas,
                    COALESCE((SUM(valor_liquido_proprietario_centavos) FILTER (
                        WHERE status_pagamento = 'pago'
                           OR status_reserva = 'confirmada'
                    )) / 100.0, 0) AS total_faturado,
                    COALESCE((SUM(valor_liquido_proprietario_centavos) FILTER (
                        WHERE status_reserva IN ('solicitada', 'aguardando_pagamento', 'pagamento_confirmado')
                          AND status_pagamento = 'pendente'
                    )) / 100.0, 0) AS total_pendente,
                    COALESCE((SUM(valor_liquido_proprietario_centavos) FILTER (
                        WHERE status_reserva NOT IN ('cancelada', 'finalizada')
                    )) / 100.0, 0) AS estimativa_faturamento
                FROM reservas
                WHERE proprietario_id = :proprietario_id
                  AND data_inicio BETWEEN :inicio AND :fim
                SQL
        );
        $summary->execute($params);
        $resumo = $summary->fetch(PDO::FETCH_ASSOC) ?: [];

        $lista = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.data_inicio,
                    r.data_fim,
                    r.quantidade_diarias,
                    r.valor_diaria,
                    (r.valor_liquido_proprietario_centavos / 100.0) AS valor_total,
                    r.status_reserva,
                    r.status_pagamento,
                    r.data_reserva,
                    c.nome AS chacara_nome,
                    c.cidade,
                    u.nome AS cliente_nome
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN usuarios u ON u.id = r.usuario_id
                WHERE r.proprietario_id = :proprietario_id
                  AND r.data_inicio BETWEEN :inicio AND :fim
                ORDER BY r.data_inicio DESC, r.id DESC
                SQL
        );
        $lista->execute($params);
        $reservas = $lista->fetchAll(PDO::FETCH_ASSOC);

        return [
            'resumo' => [
                'total_reservas' => (int) ($resumo['total_reservas'] ?? 0),
                'total_faturado' => (float) ($resumo['total_faturado'] ?? 0),
                'total_pendente' => (float) ($resumo['total_pendente'] ?? 0),
                'estimativa_faturamento' => (float) ($resumo['estimativa_faturamento'] ?? 0),
            ],
            'reservas' => $reservas,
            'reservas_principais' => array_values(array_filter(
                $reservas,
                static fn (array $reserva): bool => $reserva['status_pagamento'] === 'pago'
                    || $reserva['status_reserva'] === 'confirmada'
            )),
            'reservas_pendentes' => array_values(array_filter(
                $reservas,
                static fn (array $reserva): bool => in_array(
                    $reserva['status_reserva'],
                    ['solicitada', 'aguardando_pagamento', 'pagamento_confirmado'],
                    true
                ) && $reserva['status_pagamento'] === 'pendente'
            )),
        ];
    }

    public function buscarDetalheCliente(int $reservaId, int $usuarioId): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    r.*,
                    c.nome AS chacara_nome,
                    c.descricao AS chacara_descricao,
                    c.cidade,
                    c.regiao,
                    c.endereco,
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
                    p.nome AS proprietario_nome,
                    a.id AS avaliacao_id
                FROM reservas r
                INNER JOIN chacaras c ON c.id = r.chacara_id
                INNER JOIN proprietarios p ON p.id = r.proprietario_id
                LEFT JOIN avaliacoes a ON a.reserva_id = r.id
                WHERE r.id = :id
                  AND r.usuario_id = :usuario_id
                LIMIT 1
                SQL
        );
        $statement->execute([
            'id' => $reservaId,
            'usuario_id' => $usuarioId,
        ]);
        $reserva = $statement->fetch(PDO::FETCH_ASSOC);

        return $reserva ?: null;
    }

    public static function periodoValido(string $inicio, string $fim): bool
    {
        $dataInicio = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $dataFim = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);

        return $dataInicio !== false
            && $dataFim !== false
            && $dataInicio->format('Y-m-d') === $inicio
            && $dataFim->format('Y-m-d') === $fim
            && $dataInicio >= new DateTimeImmutable('today')
            && $dataFim > $dataInicio;
    }

    public static function calcularDiarias(string $inicio, string $fim): int
    {
        $dataInicio = new DateTimeImmutable($inicio);
        $dataFim = new DateTimeImmutable($fim);

        return (int) $dataInicio->diff($dataFim)->days;
    }

    private function bloquearCriacaoConcorrente(int $chacaraId): void
    {
        $statement = $this->db->prepare('SELECT pg_advisory_xact_lock(:chacara_id)');
        $statement->execute(['chacara_id' => $chacaraId]);
    }
}
