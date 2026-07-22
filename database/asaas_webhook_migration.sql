BEGIN;
CREATE TABLE IF NOT EXISTS asaas_webhook_eventos (
 id BIGSERIAL PRIMARY KEY, asaas_event_id VARCHAR(120) NOT NULL, tipo_evento VARCHAR(120) NOT NULL,
 tipo_recurso VARCHAR(40) NOT NULL DEFAULT 'payment', asaas_payment_id VARCHAR(120), external_reference VARCHAR(255),
 payload JSONB NOT NULL, payload_hash CHAR(64) NOT NULL, status_processamento VARCHAR(20) NOT NULL DEFAULT 'recebido',
 quantidade_tentativas INTEGER NOT NULL DEFAULT 0, ultimo_erro VARCHAR(1000), recebido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 iniciado_em TIMESTAMP, processado_em TIMESTAMP, proxima_tentativa_em TIMESTAMP, revisao_motivo VARCHAR(500),
 revisao_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL, revisao_em TIMESTAMP,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_asaas_webhook_event_id UNIQUE (asaas_event_id),
 CONSTRAINT ck_asaas_webhook_status CHECK (status_processamento IN ('recebido','processando','processado','ignorado','divergente','erro')),
 CONSTRAINT ck_asaas_webhook_tentativas CHECK (quantidade_tentativas >= 0),
 CONSTRAINT ck_asaas_webhook_hash CHECK (payload_hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_fila ON asaas_webhook_eventos (status_processamento, proxima_tentativa_em, recebido_em);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_payment ON asaas_webhook_eventos (asaas_payment_id) WHERE asaas_payment_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_recebido ON asaas_webhook_eventos (recebido_em DESC);
COMMIT;
