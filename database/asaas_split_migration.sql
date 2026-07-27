BEGIN;

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_modalidade VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_wallet_id_hash CHAR(64);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_versao INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_preparado_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_origem VARCHAR(20) NOT NULL DEFAULT 'legado';
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_status_resumo VARCHAR(30) NOT NULL DEFAULT 'sem_split_legado';

CREATE TABLE IF NOT EXISTS asaas_splits (
 id BIGSERIAL PRIMARY KEY,
 reserva_id INTEGER NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
 pagamento_id INTEGER REFERENCES pagamentos(id) ON DELETE RESTRICT,
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 asaas_subconta_id INTEGER NOT NULL REFERENCES asaas_subcontas(id) ON DELETE RESTRICT,
 ambiente VARCHAR(10) NOT NULL CHECK (ambiente IN ('sandbox','production')),
 asaas_payment_id VARCHAR(120), wallet_id VARCHAR(100) NOT NULL,
 modalidade_split VARCHAR(20) NOT NULL CHECK (modalidade_split IN ('FIXED_VALUE','TOTAL_FIXED_VALUE')),
 valor_split_centavos BIGINT NOT NULL CHECK (valor_split_centavos > 0),
 quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
 valor_por_parcela_estimado_centavos BIGINT NOT NULL CHECK (valor_por_parcela_estimado_centavos > 0),
 status_local VARCHAR(30) NOT NULL DEFAULT 'preparado' CHECK (status_local IN ('preparado','enviado','pendente','aguardando_credito','concluido','recusado','cancelado','estornado','divergente','erro','conciliacao_manual')),
 status_asaas VARCHAR(40), asaas_split_id VARCHAR(120), refusal_reason VARCHAR(500),
 payload_hash CHAR(64) NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
 divergencia BOOLEAN NOT NULL DEFAULT FALSE, divergencia_motivo VARCHAR(500), observacao_revisao VARCHAR(500),
 solicitado_em TIMESTAMP, confirmado_em TIMESTAMP, concluido_em TIMESTAMP, recusado_em TIMESTAMP,
 cancelado_em TIMESTAMP, estornado_em TIMESTAMP, ultima_sincronizacao_em TIMESTAMP,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (pagamento_id, proprietario_id), UNIQUE (asaas_payment_id, proprietario_id), UNIQUE (asaas_split_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_asaas_split_reserva_proprietario ON asaas_splits(reserva_id,proprietario_id);
CREATE INDEX IF NOT EXISTS idx_asaas_split_reserva ON asaas_splits(reserva_id);
CREATE INDEX IF NOT EXISTS idx_asaas_split_pagamento ON asaas_splits(pagamento_id) WHERE pagamento_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_asaas_split_proprietario ON asaas_splits(proprietario_id,status_local);
CREATE INDEX IF NOT EXISTS idx_asaas_split_status_datas ON asaas_splits(status_local,ultima_sincronizacao_em,criado_em);

CREATE TABLE IF NOT EXISTS historico_asaas_splits (
 id BIGSERIAL PRIMARY KEY, asaas_split_local_id BIGINT NOT NULL REFERENCES asaas_splits(id) ON DELETE RESTRICT,
 reserva_id INTEGER NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
 pagamento_id INTEGER REFERENCES pagamentos(id) ON DELETE RESTRICT,
 status_anterior VARCHAR(30), status_novo VARCHAR(30) NOT NULL,
 origem VARCHAR(20) NOT NULL CHECK (origem IN ('sistema','cobranca','webhook','administrador','sincronizacao','conciliacao')),
 tipo_evento VARCHAR(120), motivo VARCHAR(500), refusal_reason VARCHAR(500), asaas_event_id VARCHAR(120),
 metadados JSONB NOT NULL DEFAULT '{}'::jsonb, criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_historico_split_evento ON historico_asaas_splits(asaas_event_id) WHERE asaas_event_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_historico_split ON historico_asaas_splits(asaas_split_local_id,criado_em DESC,id DESC);

CREATE OR REPLACE FUNCTION proteger_snapshot_split() RETURNS trigger AS $$
BEGIN
 IF (OLD.id_cobranca_asaas IS NOT NULL OR OLD.status_pagamento='pago' OR EXISTS(SELECT 1 FROM asaas_splits s WHERE s.reserva_id=OLD.id AND s.asaas_payment_id IS NOT NULL)) AND
 ROW(NEW.split_proprietario_centavos,NEW.split_modalidade,NEW.split_wallet_id_hash,NEW.split_versao,NEW.split_preparado_em,NEW.split_origem)
 IS DISTINCT FROM ROW(OLD.split_proprietario_centavos,OLD.split_modalidade,OLD.split_wallet_id_hash,OLD.split_versao,OLD.split_preparado_em,OLD.split_origem)
 THEN RAISE EXCEPTION 'Snapshot de split imutavel'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_proteger_snapshot_split ON reservas;
CREATE TRIGGER trg_proteger_snapshot_split BEFORE UPDATE ON reservas FOR EACH ROW EXECUTE FUNCTION proteger_snapshot_split();
COMMIT;
