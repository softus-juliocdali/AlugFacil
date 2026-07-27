DO $$
BEGIN
    IF current_database() <> 'alugfacil_dev' THEN
        RAISE EXCEPTION 'Migration permitida exclusivamente em alugfacil_dev.';
    END IF;
END $$;

BEGIN;

ALTER TABLE configuracoes_financeiras
    ADD COLUMN IF NOT EXISTS taxa_operacao_pix_reserva_centavos INTEGER NOT NULL DEFAULT 200;
ALTER TABLE configuracoes_financeiras
    DROP CONSTRAINT IF EXISTS chk_config_taxa_operacao_pix;
ALTER TABLE configuracoes_financeiras
    ADD CONSTRAINT chk_config_taxa_operacao_pix CHECK (taxa_operacao_pix_reserva_centavos >= 0);
WITH nova AS (
    INSERT INTO configuracoes_financeiras
        (taxa_plataforma_percentual_bps,taxa_plataforma_fixa_centavos,
         taxa_operacao_pix_reserva_centavos,precificacao_ativa,versao)
    SELECT 0,0,200,TRUE,1
    WHERE NOT EXISTS (SELECT 1 FROM configuracoes_financeiras WHERE vigencia_fim IS NULL)
    RETURNING id
)
INSERT INTO taxas_meios_pagamento
    (configuracao_financeira_id,forma_pagamento,quantidade_parcelas,
     percentual_gateway_bps,taxa_fixa_gateway_centavos,margem_seguranca_bps,ativo)
SELECT id,'PIX',1,0,0,0,TRUE FROM nova;

ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkin_hora_inicial TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkin_hora_final TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkout_hora_inicial TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkout_hora_final TIME;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_checkin_horas;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_checkin_horas
    CHECK (checkin_hora_inicial IS NULL OR checkin_hora_final IS NULL OR checkin_hora_inicial < checkin_hora_final);
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_checkout_horas;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_checkout_horas
    CHECK (checkout_hora_inicial IS NULL OR checkout_hora_final IS NULL OR checkout_hora_inicial < checkout_hora_final);

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_operacao_pix_snapshot_centavos INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_hora_inicial_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_hora_final_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkout_hora_inicial_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkout_hora_final_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_inicio_em TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelamento_permitido_ate TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS repasse_liberavel_em TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS status_repasse VARCHAR(40);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS aceite_reserva_menos_24h BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE reservas DROP CONSTRAINT IF EXISTS chk_reservas_taxa_operacao_pix;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_taxa_operacao_pix
    CHECK (taxa_operacao_pix_snapshot_centavos IS NULL OR taxa_operacao_pix_snapshot_centavos >= 0);

CREATE TABLE IF NOT EXISTS reembolsos_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    pagamento_id BIGINT REFERENCES pagamentos(id),
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id),
    valor_pago_centavos INTEGER NOT NULL CHECK (valor_pago_centavos > 0),
    valor_reserva_centavos INTEGER NOT NULL CHECK (valor_reserva_centavos > 0),
    taxa_operacao_centavos INTEGER NOT NULL CHECK (taxa_operacao_centavos >= 0),
    valor_reembolso_centavos INTEGER NOT NULL CHECK (valor_reembolso_centavos > 0),
    tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('CANCELAMENTO_USUARIO','CANCELAMENTO_ADMIN','EXCECAO_ADMIN')),
    status_local VARCHAR(30) NOT NULL DEFAULT 'solicitado'
        CHECK (status_local IN ('solicitado','processando','concluido','falhou','conciliacao_manual','cancelado')),
    status_asaas VARCHAR(80), asaas_refund_id VARCHAR(120), asaas_payment_id VARCHAR(120),
    motivo VARCHAR(500), origem VARCHAR(30) NOT NULL,
    solicitado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processando_em TIMESTAMPTZ, concluido_em TIMESTAMPTZ, falhou_em TIMESTAMPTZ,
    ultima_sincronizacao_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_reembolso_reserva_tipo_valor UNIQUE (reserva_id, tipo, valor_reembolso_centavos)
);
CREATE INDEX IF NOT EXISTS idx_reembolsos_status ON reembolsos_reservas(status_local, solicitado_em);

CREATE TABLE IF NOT EXISTS historico_reembolsos_reservas (
    id BIGSERIAL PRIMARY KEY,
    reembolso_id BIGINT NOT NULL REFERENCES reembolsos_reservas(id),
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30), status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(30) NOT NULL, evento VARCHAR(80) NOT NULL, motivo VARCHAR(500),
    id_externo_mascarado VARCHAR(80), criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS repasses_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id BIGINT NOT NULL UNIQUE REFERENCES reservas(id),
    pagamento_id BIGINT REFERENCES pagamentos(id),
    proprietario_id BIGINT NOT NULL REFERENCES proprietarios(id),
    asaas_subconta_id BIGINT, ambiente VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    wallet_id VARCHAR(160), wallet_hash CHAR(64),
    valor_reserva_centavos INTEGER NOT NULL CHECK (valor_reserva_centavos > 0),
    valor_repasse_centavos INTEGER NOT NULL CHECK (valor_repasse_centavos = valor_reserva_centavos),
    repasse_liberavel_em TIMESTAMPTZ NOT NULL,
    status_local VARCHAR(30) NOT NULL DEFAULT 'aguardando_pagamento'
        CHECK (status_local IN ('aguardando_pagamento','aguardando_liberacao','liberado_para_repasse','processando','concluido','falhou','conciliacao_manual','cancelado')),
    status_asaas VARCHAR(80), asaas_transfer_id VARCHAR(120),
    external_reference VARCHAR(120) NOT NULL UNIQUE,
    tentativa_count INTEGER NOT NULL DEFAULT 0 CHECK (tentativa_count >= 0),
    erro_codigo VARCHAR(80), erro_motivo VARCHAR(500), conciliacao_manual BOOLEAN NOT NULL DEFAULT FALSE,
    solicitado_em TIMESTAMPTZ, processando_em TIMESTAMPTZ, concluido_em TIMESTAMPTZ,
    ultima_sincronizacao_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_repasses_fila ON repasses_reservas(status_local, repasse_liberavel_em);
CREATE UNIQUE INDEX IF NOT EXISTS uq_repasses_transfer_id ON repasses_reservas(asaas_transfer_id) WHERE asaas_transfer_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS historico_repasses_reservas (
    id BIGSERIAL PRIMARY KEY,
    repasse_id BIGINT NOT NULL REFERENCES repasses_reservas(id),
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30), status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(30) NOT NULL, evento VARCHAR(80) NOT NULL, motivo VARCHAR(500),
    asaas_transfer_id VARCHAR(120), metadados_sanitizados JSONB NOT NULL DEFAULT '{}'::jsonb,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
