BEGIN;

CREATE TABLE IF NOT EXISTS mensalidades_anuncios (
    id BIGSERIAL PRIMARY KEY,
    chacara_id INTEGER NOT NULL UNIQUE REFERENCES chacaras(id) ON DELETE RESTRICT,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    ativa BOOLEAN NOT NULL DEFAULT FALSE,
    valor_centavos BIGINT,
    status VARCHAR(20) NOT NULL DEFAULT 'SEM_MENSALIDADE',
    asaas_customer_id VARCHAR(100),
    asaas_subscription_id VARCHAR(100) UNIQUE,
    proximo_vencimento DATE,
    ultimo_pagamento_em TIMESTAMPTZ,
    configurada_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_mensalidade_status CHECK (status IN ('SEM_MENSALIDADE','PENDENTE','EM_DIA','ATRASADA','CANCELADA')),
    ultima_falha_sincronizacao TEXT,
    ultima_tentativa_sincronizacao_em TIMESTAMPTZ,
    CONSTRAINT chk_mensalidade_valor CHECK ((ativa AND valor_centavos >= 100) OR (NOT ativa AND valor_centavos IS NULL))
);

ALTER TABLE mensalidades_anuncios
    ADD COLUMN IF NOT EXISTS ultima_falha_sincronizacao TEXT,
    ADD COLUMN IF NOT EXISTS ultima_tentativa_sincronizacao_em TIMESTAMPTZ;
ALTER TABLE mensalidades_anuncios DROP CONSTRAINT IF EXISTS chk_mensalidade_valor;
ALTER TABLE mensalidades_anuncios ADD CONSTRAINT chk_mensalidade_valor
    CHECK ((ativa AND valor_centavos >= 100) OR (NOT ativa AND valor_centavos IS NULL));

CREATE TABLE IF NOT EXISTS configuracoes_mensalidades_anuncios (
    id SMALLINT PRIMARY KEY DEFAULT 1,
    valor_padrao_centavos BIGINT NOT NULL,
    atualizado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_config_mensalidade_unica CHECK (id = 1),
    CONSTRAINT chk_config_mensalidade_valor CHECK (valor_padrao_centavos >= 100)
);

CREATE TABLE IF NOT EXISTS historico_configuracoes_mensalidades_anuncios (
    id BIGSERIAL PRIMARY KEY,
    valor_anterior_centavos BIGINT,
    valor_novo_centavos BIGINT NOT NULL,
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    motivo VARCHAR(500) NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_historico_config_mensalidade_valor CHECK (valor_novo_centavos >= 100)
);

INSERT INTO configuracoes_mensalidades_anuncios (id, valor_padrao_centavos)
VALUES (1, 4990)
ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS cobrancas_mensalidades (
    id BIGSERIAL PRIMARY KEY,
    mensalidade_id BIGINT NOT NULL REFERENCES mensalidades_anuncios(id) ON DELETE RESTRICT,
    asaas_payment_id VARCHAR(100) NOT NULL UNIQUE,
    asaas_event_id VARCHAR(120) UNIQUE,
    valor_centavos BIGINT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
    vencimento DATE,
    invoice_url TEXT,
    pago_em TIMESTAMPTZ,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cobranca_mensalidade_status CHECK (status IN ('PENDENTE','PAGA','ATRASADA','CANCELADA','ESTORNADA'))
);

CREATE TABLE IF NOT EXISTS historico_mensalidades_anuncios (
    id BIGSERIAL PRIMARY KEY,
    mensalidade_id BIGINT NOT NULL REFERENCES mensalidades_anuncios(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(20),
    status_novo VARCHAR(20) NOT NULL,
    valor_centavos BIGINT,
    origem VARCHAR(30) NOT NULL,
    referencia_externa VARCHAR(120),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notificacoes (
    id BIGSERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    mensagem TEXT NOT NULL,
    link TEXT,
    chave_deduplicacao VARCHAR(180) UNIQUE,
    lida_em TIMESTAMPTZ,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mensalidades_status ON mensalidades_anuncios(ativa,status,proximo_vencimento);
CREATE INDEX IF NOT EXISTS idx_mensalidades_proprietario ON mensalidades_anuncios(proprietario_id,atualizada_em DESC);
CREATE INDEX IF NOT EXISTS idx_cobrancas_mensalidade ON cobrancas_mensalidades(mensalidade_id,vencimento DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_historico_mensalidade ON historico_mensalidades_anuncios(mensalidade_id,criado_em DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_notificacoes_usuario ON notificacoes(usuario_id,lida_em,criada_em DESC);

COMMIT;
