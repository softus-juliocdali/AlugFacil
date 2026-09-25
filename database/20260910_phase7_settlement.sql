ALTER TABLE cotacoes_reserva ADD CONSTRAINT checkout_quinze_minutos CHECK(versao_snapshot<2 OR expira_em-criado_em=INTERVAL '15 minutes');
ALTER TABLE reembolsos_reservas ALTER COLUMN valor_pago_centavos TYPE BIGINT,ALTER COLUMN valor_reserva_centavos TYPE BIGINT,ALTER COLUMN taxa_operacao_centavos TYPE BIGINT,ALTER COLUMN valor_reembolso_centavos TYPE BIGINT;
CREATE TABLE repasses_obrigacoes(
 obrigacao_id BIGINT PRIMARY KEY REFERENCES obrigacoes_reserva(id),valor_centavos BIGINT NOT NULL CHECK(valor_centavos>=0),wallet_id VARCHAR(100) NOT NULL,
 estado VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK(estado IN ('pendente','processando','concluido','falhou','manual','cancelado')),
 operacao_id BIGINT REFERENCES operacoes_financeiras(id),asaas_transfer_id VARCHAR(100) UNIQUE,status_asaas VARCHAR(40),
 criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),atualizado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE FUNCTION proteger_repasse_obrigacao() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.valor_centavos IS DISTINCT FROM (SELECT proprietario_centavos FROM obrigacoes_reserva WHERE id=NEW.obrigacao_id) THEN RAISE EXCEPTION 'Repasse excede ou diverge do direito da obrigacao' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (NEW.obrigacao_id,NEW.valor_centavos,NEW.wallet_id) IS DISTINCT FROM (OLD.obrigacao_id,OLD.valor_centavos,OLD.wallet_id) THEN RAISE EXCEPTION 'Destino e valor do repasse imutaveis' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_repasse_obrigacao BEFORE INSERT OR UPDATE ON repasses_obrigacoes FOR EACH ROW EXECUTE FUNCTION proteger_repasse_obrigacao();
CREATE TABLE historico_documentos_pagador(id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,usuario_id INTEGER NOT NULL REFERENCES usuarios(id),documento_anterior VARCHAR(14),documento_novo VARCHAR(14) NOT NULL,criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp());
CREATE FUNCTION proteger_documento_pagador() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM proprietarios WHERE usuario_id=NEW.usuario_id) THEN RAISE EXCEPTION 'Proprietario usa cadastro canonico' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND NEW.cpf_cnpj IS DISTINCT FROM OLD.cpf_cnpj AND (EXISTS(SELECT 1 FROM asaas_customers WHERE usuario_id=NEW.usuario_id) OR EXISTS(SELECT 1 FROM operacoes_financeiras WHERE tipo='customer' AND entidade='usuario:'||NEW.usuario_id)) THEN RAISE EXCEPTION 'Documento em uso financeiro exige conciliacao' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_documento_pagador BEFORE INSERT OR UPDATE ON usuario_documentos FOR EACH ROW EXECUTE FUNCTION proteger_documento_pagador();
