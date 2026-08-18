<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ConfiguracaoMensalidadeAnuncio
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= Database::getConnection();
    }

    public function atual(): array
    {
        $registro = $this->db->query(
            'SELECT * FROM configuracoes_mensalidades_anuncios WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (!$registro) {
            throw new RuntimeException('Configure o valor padrao da mensalidade antes de aprovar anuncios.');
        }

        return $registro;
    }

    public function valorPadraoCentavos(): int
    {
        return (int) $this->atual()['valor_padrao_centavos'];
    }

    public function historico(int $limite = 30): array
    {
        $statement = $this->db->prepare(
            'SELECT h.*, u.nome AS administrador_nome
             FROM historico_configuracoes_mensalidades_anuncios h
             LEFT JOIN usuarios u ON u.id = h.administrador_id
             ORDER BY h.criado_em DESC, h.id DESC
             LIMIT :limite'
        );
        $statement->bindValue(':limite', max(1, min(100, $limite)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function salvar(int $valorCentavos, int $administradorId, string $motivo): void
    {
        if ($valorCentavos < 100) {
            throw new RuntimeException('O valor padrao deve ser de pelo menos R$ 1,00.');
        }

        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new RuntimeException('Informe o motivo da alteracao do valor padrao.');
        }

        $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
            $atual = $this->db->query(
                'SELECT valor_padrao_centavos FROM configuracoes_mensalidades_anuncios WHERE id = 1' . $lock
            )->fetchColumn();
            if ($atual === false) {
                throw new RuntimeException('Configuracao padrao de mensalidade nao encontrada.');
            }

            if ((int) $atual === $valorCentavos) {
                $this->db->rollBack();
                return;
            }

            $this->db->prepare(
                'UPDATE configuracoes_mensalidades_anuncios
                 SET valor_padrao_centavos = :valor, atualizado_por = :admin,
                     atualizada_em = CURRENT_TIMESTAMP
                 WHERE id = 1'
            )->execute(['valor' => $valorCentavos, 'admin' => $administradorId]);
            $this->db->prepare(
                'INSERT INTO historico_configuracoes_mensalidades_anuncios
                    (valor_anterior_centavos, valor_novo_centavos, administrador_id, motivo)
                 VALUES (:anterior, :novo, :admin, :motivo)'
            )->execute([
                'anterior' => (int) $atual,
                'novo' => $valorCentavos,
                'admin' => $administradorId,
                'motivo' => mb_substr($motivo, 0, 500),
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
