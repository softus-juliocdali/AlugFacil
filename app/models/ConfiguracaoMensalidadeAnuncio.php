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
            'SELECT h.id,h.criado_em,h.motivo,h.administrador_id,(h.anterior->>\'valor_padrao_centavos\')::bigint AS valor_anterior_centavos,(h.nova->>\'valor_padrao_centavos\')::bigint AS valor_novo_centavos,u.nome AS administrador_nome
             FROM historico_condicoes_comerciais h
             LEFT JOIN usuarios u ON u.id = h.administrador_id
             WHERE h.tipo=\'global\'
             ORDER BY h.criado_em DESC, h.id DESC
             LIMIT :limite'
        );
        $statement->bindValue(':limite', max(1, min(100, $limite)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function salvar(int $valorCentavos,int $administradorId,string $motivo):void
    {(new \App\Services\CommercialConfigurationService($this->db))->configureGlobal((bool)$this->atual()['ativa'],$valorCentavos,$administradorId,$motivo);}
}
