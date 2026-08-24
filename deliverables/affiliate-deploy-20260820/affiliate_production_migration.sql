-- Alug Facil - modulo de afiliados
-- Migration consolidada de producao
-- Preparada em 2026-08-20
--
-- Escopo:
--   1. fundacao e configuracao global de afiliados;
--   2. atribuicao opcional em proprietarios;
--   3. ledger de comissoes, pagamentos, alocacoes e ajustes.
--
-- Dependencias deliberadamente nao recriadas:
--   usuarios, proprietarios, chacaras, mensalidades_anuncios,
--   cobrancas_mensalidades e asaas_webhook_eventos.
--
-- Execute somente apos backup, conferencia do banco e preflight do runbook.

BEGIN;

SELECT pg_advisory_xact_lock(hashtext('alugfacil_affiliate_production_migration_v1'));

DO $preflight$
DECLARE
    relacao TEXT;
BEGIN
    FOREACH relacao IN ARRAY ARRAY[
        'public.usuarios',
        'public.proprietarios',
        'public.chacaras',
        'public.mensalidades_anuncios',
        'public.cobrancas_mensalidades',
        'public.asaas_webhook_eventos'
    ]
    LOOP
        IF TO_REGCLASS(relacao) IS NULL THEN
            RAISE EXCEPTION 'Dependencia obrigatoria ausente: %', relacao;
        END IF;
    END LOOP;
END
$preflight$;

CREATE SEQUENCE IF NOT EXISTS afiliados_codigo_seq
    AS BIGINT
    START WITH 1
    INCREMENT BY 1
    NO CYCLE;

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

DO $trigger_codigo$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'trg_afiliados_codigo_imutavel'
          AND tgrelid = 'afiliados'::REGCLASS
    ) THEN
        CREATE TRIGGER trg_afiliados_codigo_imutavel
        BEFORE UPDATE OF codigo ON afiliados
        FOR EACH ROW EXECUTE FUNCTION proteger_codigo_afiliado();
    END IF;
END
$trigger_codigo$;

CREATE OR REPLACE FUNCTION atualizar_timestamp_afiliado()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $trigger_timestamp$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'trg_afiliados_atualizado_em'
          AND tgrelid = 'afiliados'::REGCLASS
    ) THEN
        CREATE TRIGGER trg_afiliados_atualizado_em
        BEFORE UPDATE ON afiliados
        FOR EACH ROW EXECUTE FUNCTION atualizar_timestamp_afiliado();
    END IF;
END
$trigger_timestamp$;

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

ALTER TABLE proprietarios
    ADD COLUMN IF NOT EXISTS afiliado_id BIGINT,
    ADD COLUMN IF NOT EXISTS afiliado_origem VARCHAR(10),
    ADD COLUMN IF NOT EXISTS afiliado_atribuido_em TIMESTAMPTZ;

DO $fk_atribuicao$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_proprietarios_afiliado'
          AND conrelid = 'proprietarios'::REGCLASS
    ) THEN
        ALTER TABLE proprietarios
            ADD CONSTRAINT fk_proprietarios_afiliado
            FOREIGN KEY (afiliado_id) REFERENCES afiliados(id)
            ON DELETE RESTRICT NOT VALID;
    END IF;
END
$fk_atribuicao$;

ALTER TABLE proprietarios
    VALIDATE CONSTRAINT fk_proprietarios_afiliado;

DO $check_atribuicao$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
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
END
$check_atribuicao$;

ALTER TABLE proprietarios
    VALIDATE CONSTRAINT chk_proprietarios_atribuicao_afiliado;

CREATE INDEX IF NOT EXISTS idx_proprietarios_afiliado
    ON proprietarios (afiliado_id)
    WHERE afiliado_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS comissoes_afiliados (
    id BIGSERIAL PRIMARY KEY,
    afiliado_id BIGINT NOT NULL REFERENCES afiliados(id) ON DELETE RESTRICT,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    chacara_id INTEGER NOT NULL REFERENCES chacaras(id) ON DELETE RESTRICT,
    mensalidade_id BIGINT NOT NULL REFERENCES mensalidades_anuncios(id) ON DELETE RESTRICT,
    cobranca_mensalidade_id BIGINT NOT NULL REFERENCES cobrancas_mensalidades(id) ON DELETE RESTRICT,
    asaas_payment_id VARCHAR(100) NOT NULL,
    valor_base_centavos BIGINT NOT NULL,
    percentual_bps SMALLINT NOT NULL,
    valor_comissao_centavos BIGINT NOT NULL,
    confirmado_em TIMESTAMPTZ NOT NULL,
    disponivel_em TIMESTAMPTZ NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'EM_ABERTO',
    evento_confirmacao_id VARCHAR(120),
    estornada_em TIMESTAMPTZ,
    evento_estorno_id VARCHAR(120),
    motivo_estorno VARCHAR(500),
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_comissao_cobranca_mensalidade UNIQUE (cobranca_mensalidade_id),
    CONSTRAINT uq_comissao_asaas_payment UNIQUE (asaas_payment_id),
    CONSTRAINT chk_comissao_valor_base CHECK (valor_base_centavos > 0),
    CONSTRAINT chk_comissao_percentual CHECK (percentual_bps > 0 AND percentual_bps <= 10000),
    CONSTRAINT chk_comissao_valor CHECK (valor_comissao_centavos >= 0),
    CONSTRAINT chk_comissao_periodo CHECK (disponivel_em = confirmado_em + INTERVAL '7 days'),
    CONSTRAINT chk_comissao_status CHECK (status IN ('EM_ABERTO','DISPONIVEL','PAGA','CANCELADA','ESTORNADA'))
);

CREATE TABLE IF NOT EXISTS pagamentos_afiliados (
    id BIGSERIAL PRIMARY KEY,
    afiliado_id BIGINT NOT NULL REFERENCES afiliados(id) ON DELETE RESTRICT,
    valor_centavos BIGINT NOT NULL,
    data_pagamento DATE NOT NULL,
    referencia VARCHAR(180),
    observacao VARCHAR(1000),
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    registrado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_pagamento_afiliado_valor CHECK (valor_centavos > 0)
);

CREATE TABLE IF NOT EXISTS alocacoes_pagamentos_afiliados (
    id BIGSERIAL PRIMARY KEY,
    pagamento_afiliado_id BIGINT NOT NULL REFERENCES pagamentos_afiliados(id) ON DELETE RESTRICT,
    comissao_afiliado_id BIGINT NOT NULL REFERENCES comissoes_afiliados(id) ON DELETE RESTRICT,
    valor_centavos BIGINT NOT NULL,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_alocacao_pagamento_comissao UNIQUE (pagamento_afiliado_id, comissao_afiliado_id),
    CONSTRAINT chk_alocacao_valor CHECK (valor_centavos > 0)
);

CREATE TABLE IF NOT EXISTS ajustes_afiliados (
    id BIGSERIAL PRIMARY KEY,
    afiliado_id BIGINT NOT NULL REFERENCES afiliados(id) ON DELETE RESTRICT,
    comissao_afiliado_id BIGINT NOT NULL REFERENCES comissoes_afiliados(id) ON DELETE RESTRICT,
    tipo VARCHAR(40) NOT NULL,
    valor_centavos BIGINT NOT NULL,
    asaas_event_id VARCHAR(120),
    motivo VARCHAR(500) NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_ajuste_estorno_comissao UNIQUE (comissao_afiliado_id, tipo),
    CONSTRAINT chk_ajuste_tipo CHECK (tipo IN ('ESTORNO_COMISSAO_PAGA')),
    CONSTRAINT chk_ajuste_negativo CHECK (valor_centavos < 0)
);

CREATE INDEX IF NOT EXISTS idx_comissoes_afiliado_liberacao
    ON comissoes_afiliados (afiliado_id, disponivel_em, id);
CREATE INDEX IF NOT EXISTS idx_comissoes_chacara
    ON comissoes_afiliados (chacara_id, confirmado_em DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_pagamentos_afiliado
    ON pagamentos_afiliados (afiliado_id, data_pagamento DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_alocacoes_comissao
    ON alocacoes_pagamentos_afiliados (comissao_afiliado_id, id);
CREATE INDEX IF NOT EXISTS idx_ajustes_afiliado
    ON ajustes_afiliados (afiliado_id, criado_em DESC, id DESC);

COMMIT;
