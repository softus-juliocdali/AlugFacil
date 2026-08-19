BEGIN;

ALTER TABLE proprietarios
    ADD COLUMN IF NOT EXISTS cpf VARCHAR(11);

ALTER TABLE proprietarios
    DROP CONSTRAINT IF EXISTS chk_proprietarios_cpf;

ALTER TABLE proprietarios
    ADD CONSTRAINT chk_proprietarios_cpf
        CHECK (cpf IS NULL OR cpf ~ '^[0-9]{11}$');

CREATE UNIQUE INDEX IF NOT EXISTS uq_proprietarios_cpf
    ON proprietarios (cpf)
    WHERE cpf IS NOT NULL;

COMMIT;
