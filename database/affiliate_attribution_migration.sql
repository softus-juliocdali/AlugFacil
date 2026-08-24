BEGIN;

ALTER TABLE proprietarios
    ADD COLUMN IF NOT EXISTS afiliado_id BIGINT,
    ADD COLUMN IF NOT EXISTS afiliado_origem VARCHAR(10),
    ADD COLUMN IF NOT EXISTS afiliado_atribuido_em TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_proprietarios_afiliado'
          AND conrelid = 'proprietarios'::REGCLASS
    ) THEN
        ALTER TABLE proprietarios
            ADD CONSTRAINT fk_proprietarios_afiliado
            FOREIGN KEY (afiliado_id) REFERENCES afiliados(id)
            ON DELETE RESTRICT NOT VALID;
    END IF;
END $$;

ALTER TABLE proprietarios VALIDATE CONSTRAINT fk_proprietarios_afiliado;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_proprietarios_atribuicao_afiliado'
          AND conrelid = 'proprietarios'::REGCLASS
    ) THEN
        ALTER TABLE proprietarios
            ADD CONSTRAINT chk_proprietarios_atribuicao_afiliado
            CHECK (
                (afiliado_id IS NULL AND afiliado_origem IS NULL AND afiliado_atribuido_em IS NULL)
                OR
                (afiliado_id IS NOT NULL AND afiliado_origem IN ('link', 'codigo') AND afiliado_atribuido_em IS NOT NULL)
            ) NOT VALID;
    END IF;
END $$;

ALTER TABLE proprietarios VALIDATE CONSTRAINT chk_proprietarios_atribuicao_afiliado;

CREATE INDEX IF NOT EXISTS idx_proprietarios_afiliado
    ON proprietarios (afiliado_id)
    WHERE afiliado_id IS NOT NULL;

COMMIT;
