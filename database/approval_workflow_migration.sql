BEGIN;

ALTER TABLE proprietarios DROP CONSTRAINT IF EXISTS chk_proprietarios_status;
ALTER TABLE proprietarios
    ADD CONSTRAINT chk_proprietarios_status
    CHECK (status IN ('pendente', 'ativo', 'rejeitado', 'bloqueado'));
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS motivo_status TEXT;
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS status_decidido_em TIMESTAMP;
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS status_decidido_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_aprovacao VARCHAR(15);
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_operacional VARCHAR(15);
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS motivo_status TEXT;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_decidido_em TIMESTAMP;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_decidido_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

UPDATE chacaras
SET status_aprovacao = CASE
        WHEN status = 'pendente' THEN 'pendente'
        WHEN status = 'bloqueada' THEN 'bloqueada'
        ELSE 'aprovada'
    END,
    status_operacional = CASE
        WHEN status = 'disponivel' THEN 'disponivel'
        ELSE 'indisponivel'
    END
WHERE status_aprovacao IS NULL OR status_operacional IS NULL;

ALTER TABLE chacaras ALTER COLUMN status_aprovacao SET DEFAULT 'pendente';
ALTER TABLE chacaras ALTER COLUMN status_aprovacao SET NOT NULL;
ALTER TABLE chacaras ALTER COLUMN status_operacional SET DEFAULT 'indisponivel';
ALTER TABLE chacaras ALTER COLUMN status_operacional SET NOT NULL;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_status_aprovacao;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_status_aprovacao
    CHECK (status_aprovacao IN ('pendente', 'aprovada', 'rejeitada', 'bloqueada'));
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_status_operacional;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_status_operacional
    CHECK (status_operacional IN ('disponivel', 'indisponivel'));

CREATE TABLE IF NOT EXISTS historico_status_proprietarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(15) NOT NULL,
    status_novo VARCHAR(15) NOT NULL,
    motivo TEXT,
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS historico_status_chacaras (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chacara_id INTEGER NOT NULL REFERENCES chacaras(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(15) NOT NULL,
    status_novo VARCHAR(15) NOT NULL,
    motivo TEXT,
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_chacaras_publicacao
    ON chacaras (status_aprovacao, status_operacional, proprietario_id);
CREATE INDEX IF NOT EXISTS idx_historico_proprietarios_entidade
    ON historico_status_proprietarios (proprietario_id, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_historico_chacaras_entidade
    ON historico_status_chacaras (chacara_id, criado_em DESC);

COMMIT;

-- Mapeamento preservado: disponivel => aprovada/disponivel;
-- indisponivel => aprovada/indisponivel; pendente => pendente/indisponivel;
-- bloqueada => bloqueada/indisponivel.
