<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class AffiliateCommissionConfig extends Model
{
    public function current(): array
    {
        $row = $this->db->query(
            'SELECT percentual_bps, atualizado_por, criado_em, atualizado_em
             FROM configuracoes_comissao_afiliados WHERE id = 1'
        )->fetch();
        return $row ?: ['percentual_bps' => 1000];
    }

    public function update(int $basisPoints, int $administratorId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO configuracoes_comissao_afiliados (id, percentual_bps, atualizado_por)
             VALUES (1, :percentual, :admin)
             ON CONFLICT (id) DO UPDATE SET percentual_bps=EXCLUDED.percentual_bps,
                 atualizado_por=EXCLUDED.atualizado_por, atualizado_em=CURRENT_TIMESTAMP'
        );
        $statement->execute(['percentual' => $basisPoints, 'admin' => $administratorId]);
    }
}
