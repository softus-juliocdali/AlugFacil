BEGIN;

CREATE SEQUENCE IF NOT EXISTS afiliados_codigo_seq AS BIGINT START WITH 1 INCREMENT BY 1 NO CYCLE;

CREATE OR REPLACE FUNCTION formatar_codigo_afiliado(valor BIGINT)
RETURNS TEXT AS $$
    SELECT 'AF' || LPAD(valor::TEXT, GREATEST(4, LENGTH(valor::TEXT)), '0');
$$ LANGUAGE SQL IMMUTABLE STRICT;

CREATE TABLE IF NOT EXISTS afiliados (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(32) NOT NULL DEFAULT formatar_codigo_afiliado(NEXTVAL('afiliados_codigo_seq')),
    nome VARCHAR(150) NOT NULL,
    cpf_cnpj VARCHAR(14) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    email VARCHAR(180) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    chave_pix VARCHAR(150) NOT NULL,
    tipo_chave_pix VARCHAR(20) NOT NULL,
    banco VARCHAR(120),
    observacoes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo',
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_afiliados_codigo UNIQUE (codigo),
    CONSTRAINT uq_afiliados_cpf_cnpj UNIQUE (cpf_cnpj),
    CONSTRAINT chk_afiliados_codigo CHECK (codigo ~ '^AF[0-9]{4,}$'),
    CONSTRAINT chk_afiliados_documento CHECK (cpf_cnpj ~ '^[0-9]{11}$' OR cpf_cnpj ~ '^[0-9]{14}$'),
    CONSTRAINT chk_afiliados_status CHECK (status IN ('ativo', 'bloqueado')),
    CONSTRAINT chk_afiliados_tipo_pix CHECK (tipo_chave_pix IN ('cpf', 'cnpj', 'email', 'telefone', 'aleatoria'))
);

-- Atualiza instalacoes anteriores sem modificar nenhum codigo ja emitido.
ALTER TABLE afiliados
    ALTER COLUMN codigo SET DEFAULT formatar_codigo_afiliado(NEXTVAL('afiliados_codigo_seq'));

ALTER SEQUENCE afiliados_codigo_seq OWNED BY afiliados.codigo;

CREATE UNIQUE INDEX IF NOT EXISTS uq_afiliados_email_lower ON afiliados (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_afiliados_status ON afiliados (status);
CREATE INDEX IF NOT EXISTS idx_afiliados_nome ON afiliados (nome);

CREATE OR REPLACE FUNCTION proteger_codigo_afiliado()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.codigo IS DISTINCT FROM OLD.codigo THEN
        RAISE EXCEPTION 'O codigo do afiliado e imutavel.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_afiliados_codigo_imutavel' AND tgrelid = 'afiliados'::REGCLASS
    ) THEN
        CREATE TRIGGER trg_afiliados_codigo_imutavel
        BEFORE UPDATE OF codigo ON afiliados
        FOR EACH ROW EXECUTE FUNCTION proteger_codigo_afiliado();
    END IF;
END $$;

CREATE OR REPLACE FUNCTION atualizar_timestamp_afiliado()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_afiliados_atualizado_em' AND tgrelid = 'afiliados'::REGCLASS
    ) THEN
        CREATE TRIGGER trg_afiliados_atualizado_em
        BEFORE UPDATE ON afiliados
        FOR EACH ROW EXECUTE FUNCTION atualizar_timestamp_afiliado();
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS configuracoes_comissao_afiliados (
    id SMALLINT PRIMARY KEY DEFAULT 1,
    percentual_bps SMALLINT NOT NULL,
    atualizado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_config_comissao_afiliado_unica CHECK (id = 1),
    CONSTRAINT chk_config_comissao_afiliado_percentual CHECK (percentual_bps > 0 AND percentual_bps <= 10000)
);

INSERT INTO configuracoes_comissao_afiliados (id, percentual_bps)
VALUES (1, 1000)
ON CONFLICT (id) DO NOTHING;

COMMIT;
