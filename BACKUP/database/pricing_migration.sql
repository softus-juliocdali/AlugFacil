BEGIN;

CREATE TABLE IF NOT EXISTS configuracoes_financeiras (
    id BIGSERIAL PRIMARY KEY,
    taxa_plataforma_percentual_bps INTEGER NOT NULL CHECK (taxa_plataforma_percentual_bps BETWEEN 0 AND 9999),
    taxa_plataforma_fixa_centavos BIGINT NOT NULL CHECK (taxa_plataforma_fixa_centavos >= 0),
    precificacao_ativa BOOLEAN NOT NULL DEFAULT FALSE,
    moeda CHAR(3) NOT NULL DEFAULT 'BRL' CHECK (moeda = 'BRL'),
    versao INTEGER NOT NULL UNIQUE CHECK (versao > 0),
    vigencia_inicio TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vigencia_fim TIMESTAMPTZ,
    criado_por_admin_id INTEGER REFERENCES usuarios(id),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (vigencia_fim IS NULL OR vigencia_fim > vigencia_inicio)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_configuracao_financeira_vigente ON configuracoes_financeiras ((1)) WHERE vigencia_fim IS NULL;

CREATE TABLE IF NOT EXISTS taxas_meios_pagamento (
    id BIGSERIAL PRIMARY KEY,
    configuracao_financeira_id BIGINT NOT NULL REFERENCES configuracoes_financeiras(id),
    forma_pagamento VARCHAR(20) NOT NULL CHECK (forma_pagamento IN ('PIX','CREDIT_CARD','BOLETO')),
    quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
    percentual_gateway_bps INTEGER NOT NULL CHECK (percentual_gateway_bps BETWEEN 0 AND 9999),
    taxa_fixa_gateway_centavos BIGINT NOT NULL CHECK (taxa_fixa_gateway_centavos >= 0),
    margem_seguranca_bps INTEGER NOT NULL DEFAULT 0 CHECK (margem_seguranca_bps BETWEEN 0 AND 9999),
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (configuracao_financeira_id, forma_pagamento, quantidade_parcelas),
    CHECK (forma_pagamento <> 'PIX' OR quantidade_parcelas = 1)
);

CREATE TABLE IF NOT EXISTS historico_configuracoes_financeiras (
    id BIGSERIAL PRIMARY KEY,
    configuracao_anterior JSONB,
    configuracao_nova JSONB NOT NULL,
    administrador_id INTEGER NOT NULL REFERENCES usuarios(id),
    motivo VARCHAR(500) NOT NULL CHECK (length(trim(motivo)) > 0),
    versao INTEGER NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cotacoes_reserva (
    id UUID PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
    chacara_id INTEGER NOT NULL REFERENCES chacaras(id),
    configuracao_financeira_id BIGINT NOT NULL REFERENCES configuracoes_financeiras(id),
    versao_configuracao INTEGER NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    forma_pagamento VARCHAR(20) NOT NULL CHECK (forma_pagamento IN ('PIX','CREDIT_CARD')),
    quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
    detalhes JSONB NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em TIMESTAMPTZ NOT NULL,
    consumida_em TIMESTAMPTZ,
    CHECK (data_fim > data_inicio),
    CHECK (expira_em > criado_em)
);
CREATE INDEX IF NOT EXISTS idx_cotacoes_reserva_validade ON cotacoes_reserva (usuario_id, expira_em) WHERE consumida_em IS NULL;

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_diaria_liquido_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_hospedagem_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_liquido_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_plataforma_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_gateway_estimada_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_total_cliente_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS plataforma_percentual_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS plataforma_fixa_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS gateway_percentual_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS gateway_fixa_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS margem_seguranca_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS forma_pagamento VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS quantidade_parcelas INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS versao_precificacao INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS origem_precificacao VARCHAR(10);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS precificacao_detalhes JSONB;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS precificado_em TIMESTAMPTZ;

UPDATE reservas SET
  valor_diaria_liquido_proprietario_centavos = ROUND(valor_diaria * 100)::BIGINT,
  valor_hospedagem_centavos = ROUND(valor_total * 100)::BIGINT,
  valor_liquido_proprietario_centavos = ROUND(valor_total * 100)::BIGINT,
  taxa_plataforma_centavos = 0,
  taxa_gateway_estimada_centavos = 0,
  valor_total_cliente_centavos = ROUND(valor_total * 100)::BIGINT,
  plataforma_percentual_bps = 0, plataforma_fixa_centavos = 0,
  gateway_percentual_bps = 0, gateway_fixa_centavos = 0, margem_seguranca_bps = 0,
  quantidade_parcelas = 1, versao_precificacao = 0, origem_precificacao = 'legado',
  precificacao_detalhes = jsonb_build_object('origem','legado'), precificado_em = COALESCE(data_reserva, CURRENT_TIMESTAMP)
WHERE valor_total_cliente_centavos IS NULL;

DO $$ BEGIN
IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='chk_reservas_snapshot_precos') THEN
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_snapshot_precos CHECK (
  valor_diaria_liquido_proprietario_centavos >= 0 AND valor_hospedagem_centavos >= 0 AND
  valor_liquido_proprietario_centavos >= 0 AND taxa_plataforma_centavos >= 0 AND
  taxa_gateway_estimada_centavos >= 0 AND valor_total_cliente_centavos >= valor_liquido_proprietario_centavos + taxa_plataforma_centavos
) NOT VALID;
END IF;
END $$;
ALTER TABLE reservas VALIDATE CONSTRAINT chk_reservas_snapshot_precos;

CREATE OR REPLACE FUNCTION proteger_snapshot_financeiro_pago() RETURNS trigger AS $$
BEGIN
 IF OLD.status_pagamento='pago' AND ROW(
  NEW.valor_diaria_liquido_proprietario_centavos,NEW.valor_hospedagem_centavos,NEW.valor_liquido_proprietario_centavos,
  NEW.taxa_plataforma_centavos,NEW.taxa_gateway_estimada_centavos,NEW.valor_total_cliente_centavos,
  NEW.plataforma_percentual_bps,NEW.plataforma_fixa_centavos,NEW.gateway_percentual_bps,NEW.gateway_fixa_centavos,
  NEW.margem_seguranca_bps,NEW.forma_pagamento,NEW.quantidade_parcelas,NEW.versao_precificacao
 ) IS DISTINCT FROM ROW(
  OLD.valor_diaria_liquido_proprietario_centavos,OLD.valor_hospedagem_centavos,OLD.valor_liquido_proprietario_centavos,
  OLD.taxa_plataforma_centavos,OLD.taxa_gateway_estimada_centavos,OLD.valor_total_cliente_centavos,
  OLD.plataforma_percentual_bps,OLD.plataforma_fixa_centavos,OLD.gateway_percentual_bps,OLD.gateway_fixa_centavos,
  OLD.margem_seguranca_bps,OLD.forma_pagamento,OLD.quantidade_parcelas,OLD.versao_precificacao
 ) THEN RAISE EXCEPTION 'Snapshot financeiro de reserva paga e imutavel'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='trg_proteger_snapshot_financeiro_pago') THEN
CREATE TRIGGER trg_proteger_snapshot_financeiro_pago BEFORE UPDATE ON reservas FOR EACH ROW EXECUTE FUNCTION proteger_snapshot_financeiro_pago();
END IF; END $$;

COMMIT;
