BEGIN;

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS expira_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_por_tipo VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_por_id INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS motivo_cancelamento VARCHAR(500);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelamento_solicitado_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS inicio_real_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS finalizada_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS ultima_transicao_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS versao_status INTEGER NOT NULL DEFAULT 1;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS divergencia_pagamento BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE reservas DROP CONSTRAINT IF EXISTS chk_reservas_status;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status CHECK (status_reserva IN (
    'solicitada','aguardando_pagamento','pagamento_confirmado','confirmada',
    'em_andamento','finalizada','cancelamento_solicitado','cancelada','expirada','estornada','disputa'
));

CREATE TABLE IF NOT EXISTS historico_status_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id INTEGER NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    motivo VARCHAR(500),
    origem VARCHAR(20) NOT NULL,
    responsavel_tipo VARCHAR(20) NOT NULL,
    responsavel_id INTEGER,
    metadados JSONB,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reservas_expiracao
    ON reservas (expira_em, id) WHERE status_reserva = 'aguardando_pagamento';
CREATE INDEX IF NOT EXISTS idx_reservas_bloqueio_datas
    ON reservas (chacara_id, data_inicio, data_fim, status_reserva);
CREATE INDEX IF NOT EXISTS idx_historico_reserva
    ON historico_status_reservas (reserva_id, criado_em DESC, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pagamento_ativo_reserva
    ON pagamentos (reserva_id) WHERE status_pagamento IN ('pendente','pago');

-- Reservas historicas ficam intactas. O prazo e atribuido apenas a novas reservas.
COMMIT;
