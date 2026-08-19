DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'aceites_financeiros_proprietarios'
          AND column_name = 'versao_documento'
          AND data_type = 'character varying'
          AND character_maximum_length IS NOT NULL
          AND character_maximum_length < 100
    ) THEN
        ALTER TABLE aceites_financeiros_proprietarios
            ALTER COLUMN versao_documento TYPE VARCHAR(100);
    END IF;
END
$$;
