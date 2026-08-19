BEGIN;

ALTER TABLE chacaras
    ADD COLUMN IF NOT EXISTS tipo_imovel VARCHAR(20) NOT NULL DEFAULT 'chacara';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_chacaras_tipo_imovel'
    ) THEN
        ALTER TABLE chacaras
        ADD CONSTRAINT chk_chacaras_tipo_imovel
        CHECK (tipo_imovel IN ('chacara', 'sitio', 'area_lazer'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_chacaras_tipo_imovel
    ON chacaras (tipo_imovel);

COMMIT;
