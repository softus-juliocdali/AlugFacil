ALTER TABLE reservas DROP CONSTRAINT chk_reservas_status_pagamento;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status_pagamento CHECK(status_pagamento IN ('pendente','parcialmente_pago','pago','cancelado','reembolso_processando','parcialmente_estornado','estornado'));
ALTER TABLE reservas ADD COLUMN entrada_confirmada_em TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN tipo_cancelamento VARCHAR(30) CHECK(tipo_cancelamento IN ('voluntario','inadimplencia','administrativo_excepcional'));
CREATE TABLE cronogramas_reserva(
 reserva_id INTEGER PRIMARY KEY REFERENCES reservas(id),referencia_entrada_em TIMESTAMPTZ NOT NULL,limite_ultima_parcela TIMESTAMPTZ NOT NULL,
 quantidade_parcelas INTEGER NOT NULL CHECK(quantidade_parcelas BETWEEN 1 AND 18),regra VARCHAR(80) NOT NULL,criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE FUNCTION proteger_cronograma() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Referencia do cronograma imutavel' USING ERRCODE='23514'; END $$;
CREATE TRIGGER proteger_cronograma BEFORE UPDATE ON cronogramas_reserva FOR EACH ROW EXECUTE FUNCTION proteger_cronograma();
CREATE TABLE autorizacoes_pagamento_reserva(
 reserva_id INTEGER PRIMARY KEY REFERENCES reservas(id),usuario_id INTEGER NOT NULL REFERENCES usuarios(id),meio VARCHAR(20) NOT NULL CHECK(meio IN ('CREDIT_CARD','PIX_AUTOMATIC')),
 texto_aceito TEXT NOT NULL,versao VARCHAR(40) NOT NULL,aceito_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),revogado_em TIMESTAMPTZ,
 ambiente VARCHAR(12) NOT NULL DEFAULT 'sandbox' CHECK(ambiente='sandbox'),conta_gateway VARCHAR(100),asaas_customer_id VARCHAR(100),autorizacao_asaas_id VARCHAR(100),
 segredo_cifrado TEXT,nonce TEXT,tag TEXT,estado VARCHAR(30) NOT NULL DEFAULT 'aguardando_autorizacao' CHECK(estado IN ('aguardando_autorizacao','ativa','revogada','conciliacao_manual')),
 CHECK((segredo_cifrado IS NULL AND nonce IS NULL AND tag IS NULL) OR (segredo_cifrado IS NOT NULL AND nonce IS NOT NULL AND tag IS NOT NULL))
);
CREATE TABLE cancelamentos_administrativos_reserva(
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,reserva_id INTEGER NOT NULL UNIQUE REFERENCES reservas(id),administrador_id INTEGER NOT NULL REFERENCES usuarios(id),
 motivo TEXT NOT NULL CHECK(length(trim(motivo))>=10),politica_financeira TEXT NOT NULL CHECK(length(trim(politica_financeira))>=10),
 recebido_centavos BIGINT NOT NULL CHECK(recebido_centavos>=0),repassado_centavos BIGINT NOT NULL CHECK(repassado_centavos>=0),criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE tarefas_financeiras_reserva DROP CONSTRAINT tarefas_financeiras_reserva_tipo_check;
ALTER TABLE tarefas_financeiras_reserva ADD CONSTRAINT tarefas_financeiras_reserva_tipo_check CHECK(tipo IN ('cancelar_cobranca','reembolso','repasse','emitir_parcela','ativar_recorrencia','cancelar_recorrencia'));
