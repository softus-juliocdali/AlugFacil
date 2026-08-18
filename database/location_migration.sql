BEGIN;

ALTER TABLE chacaras
    ADD COLUMN IF NOT EXISTS estado CHAR(2);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_chacaras_estado'
          AND conrelid = 'chacaras'::regclass
    ) THEN
        ALTER TABLE chacaras
            ADD CONSTRAINT chk_chacaras_estado
            CHECK (
                estado IS NULL OR estado IN (
                    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
                    'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
                    'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
                )
            ) NOT VALID;
    END IF;
END $$;

ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_estado;

COMMIT;
