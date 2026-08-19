DO $$
BEGIN
    IF current_database() <> 'alugfacil_dev' THEN
        RAISE EXCEPTION 'Migration permitida exclusivamente em alugfacil_dev.';
    END IF;
END $$;
BEGIN;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS pix_qr_code_base64 TEXT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS pix_copia_cola TEXT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS asaas_status VARCHAR(80);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS asaas_vencimento DATE;
COMMIT;
