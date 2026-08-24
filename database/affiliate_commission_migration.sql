BEGIN;

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
    CONSTRAINT uq_alocacao_pagamento_comissao UNIQUE (pagamento_afiliado_id,comissao_afiliado_id),
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
    CONSTRAINT uq_ajuste_estorno_comissao UNIQUE (comissao_afiliado_id,tipo),
    CONSTRAINT chk_ajuste_tipo CHECK (tipo IN ('ESTORNO_COMISSAO_PAGA')),
    CONSTRAINT chk_ajuste_negativo CHECK (valor_centavos < 0)
);

CREATE INDEX IF NOT EXISTS idx_comissoes_afiliado_liberacao ON comissoes_afiliados(afiliado_id,disponivel_em,id);
CREATE INDEX IF NOT EXISTS idx_comissoes_chacara ON comissoes_afiliados(chacara_id,confirmado_em DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_pagamentos_afiliado ON pagamentos_afiliados(afiliado_id,data_pagamento DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_alocacoes_comissao ON alocacoes_pagamentos_afiliados(comissao_afiliado_id,id);
CREATE INDEX IF NOT EXISTS idx_ajustes_afiliado ON ajustes_afiliados(afiliado_id,criado_em DESC,id DESC);

COMMIT;
