CREATE FUNCTION agenda_ocupada(c BIGINT, inicio DATE, fim DATE, ignorar_reserva BIGINT DEFAULT NULL, ignorar_cotacao UUID DEFAULT NULL) RETURNS BOOLEAN LANGUAGE sql VOLATILE AS $$
 SELECT EXISTS(SELECT 1 FROM reservas r WHERE r.chacara_id=c AND r.id IS DISTINCT FROM ignorar_reserva AND r.data_inicio<fim AND r.data_fim>inicio
 AND r.status_reserva IN ('aguardando_pagamento','pagamento_confirmado','confirmada','em_andamento','cancelamento_solicitado','disputa')
 AND (r.status_reserva<>'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em>clock_timestamp()))
 OR EXISTS(SELECT 1 FROM cotacoes_reserva q WHERE q.chacara_id=c AND q.id IS DISTINCT FROM ignorar_cotacao AND q.versao_snapshot>=2 AND q.consumida_em IS NULL AND q.cancelada_em IS NULL AND q.expira_em>clock_timestamp() AND q.data_inicio<fim AND q.data_fim>inicio)
 OR EXISTS(SELECT 1 FROM disponibilidades d WHERE d.chacara_id=c AND d.data>=inicio AND d.data<fim AND d.status IN ('bloqueado','reservado'));
$$;
CREATE FUNCTION proteger_hold_cotacao() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.versao_snapshot>=2 THEN
  PERFORM pg_advisory_xact_lock(NEW.chacara_id::bigint);
  IF agenda_ocupada(NEW.chacara_id,NEW.data_inicio,NEW.data_fim,NULL,NEW.id) THEN RAISE EXCEPTION 'Periodo em checkout ou reservado' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_hold_cotacao BEFORE INSERT ON cotacoes_reserva FOR EACH ROW EXECUTE FUNCTION proteger_hold_cotacao();
CREATE FUNCTION proteger_agenda_reserva() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(NEW.chacara_id::bigint);
 IF TG_OP='UPDATE' AND OLD.schema_financeiro>=2 THEN
  IF OLD.status_reserva IN ('cancelada','expirada','estornada','finalizada') AND NEW.status_reserva NOT IN ('cancelada','expirada','estornada','finalizada') THEN RAISE EXCEPTION 'Reserva final nao pode ser reativada' USING ERRCODE='23514'; END IF;
  IF OLD.status_reserva='aguardando_pagamento' AND NEW.status_reserva IN ('confirmada','pagamento_confirmado') AND OLD.expira_em<=clock_timestamp() THEN RAISE EXCEPTION 'Checkout expirado nao pode confirmar' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.status_reserva IN ('aguardando_pagamento','pagamento_confirmado','confirmada','em_andamento','cancelamento_solicitado','disputa')
 AND (NEW.status_reserva<>'aguardando_pagamento' OR NEW.expira_em IS NULL OR NEW.expira_em>clock_timestamp())
 AND agenda_ocupada(NEW.chacara_id,NEW.data_inicio,NEW.data_fim,NEW.id,NEW.cotacao_id)
 THEN RAISE EXCEPTION 'Periodo em checkout ou reservado' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_agenda_reserva BEFORE INSERT OR UPDATE OF status_reserva,data_inicio,data_fim,chacara_id ON reservas FOR EACH ROW EXECUTE FUNCTION proteger_agenda_reserva();
CREATE FUNCTION proteger_bloqueio_manual() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(NEW.chacara_id::bigint);
 IF NEW.status IN ('bloqueado','reservado') AND (
 EXISTS(SELECT 1 FROM reservas r WHERE r.chacara_id=NEW.chacara_id AND r.data_inicio<=NEW.data AND r.data_fim>NEW.data AND r.status_reserva IN ('aguardando_pagamento','pagamento_confirmado','confirmada','em_andamento','cancelamento_solicitado','disputa') AND (r.status_reserva<>'aguardando_pagamento' OR r.expira_em IS NULL OR r.expira_em>clock_timestamp()))
 OR EXISTS(SELECT 1 FROM cotacoes_reserva q WHERE q.chacara_id=NEW.chacara_id AND q.versao_snapshot>=2 AND q.data_inicio<=NEW.data AND q.data_fim>NEW.data AND q.consumida_em IS NULL AND q.cancelada_em IS NULL AND q.expira_em>clock_timestamp()))
 THEN RAISE EXCEPTION 'Bloqueio manual conflita com reserva ou checkout' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_bloqueio_manual BEFORE INSERT OR UPDATE ON disponibilidades FOR EACH ROW EXECUTE FUNCTION proteger_bloqueio_manual();
CREATE TABLE obrigacoes_reserva(
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 reserva_id INTEGER NOT NULL REFERENCES reservas(id),numero INTEGER NOT NULL CHECK(numero BETWEEN 0 AND 18),tipo VARCHAR(10) NOT NULL CHECK(tipo IN ('integral','entrada','parcela')),
 hospedagem_centavos BIGINT NOT NULL CHECK(hospedagem_centavos>0),taxa_operacional_centavos BIGINT NOT NULL CHECK(taxa_operacional_centavos>=0),comissao_centavos BIGINT NOT NULL CHECK(comissao_centavos>=0),proprietario_centavos BIGINT NOT NULL CHECK(proprietario_centavos>=0),total_centavos BIGINT NOT NULL,
 vencimento_em TIMESTAMPTZ NOT NULL,cancelamento_em TIMESTAMPTZ,forma_pagamento VARCHAR(20) NOT NULL CHECK(forma_pagamento IN ('PIX','CREDIT_CARD','BOLETO','PIX_AUTOMATIC')),
 estado VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK(estado IN ('pendente','paga','cancelada','estornada','conciliacao_manual')),
 pago_centavos BIGINT NOT NULL DEFAULT 0 CHECK(pago_centavos>=0),pago_em TIMESTAMPTZ,liquidado_em TIMESTAMPTZ,
 asaas_payment_id VARCHAR(100) UNIQUE,invoice_url TEXT,operacao_id BIGINT REFERENCES operacoes_financeiras(id),asaas_customer_id VARCHAR(100),
 criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),atualizado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(reserva_id,numero),CHECK(total_centavos=hospedagem_centavos+taxa_operacional_centavos),CHECK(proprietario_centavos+comissao_centavos=hospedagem_centavos)
);
CREATE FUNCTION proteger_obrigacao_reserva() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE; p JSONB;
BEGIN
 SELECT * INTO r FROM reservas WHERE id=NEW.reserva_id;
 p=r.precificacao_detalhes->'pagamentos'->NEW.numero;
 IF r.schema_financeiro<>2 OR p IS NULL OR NEW.tipo IS DISTINCT FROM p->>'tipo' OR NEW.hospedagem_centavos IS DISTINCT FROM (p->>'hospedagem_centavos')::bigint OR NEW.taxa_operacional_centavos IS DISTINCT FROM (p->>'taxa_operacional_centavos')::bigint OR NEW.comissao_centavos IS DISTINCT FROM (p->>'comissao_centavos')::bigint OR NEW.proprietario_centavos IS DISTINCT FROM (p->>'proprietario_centavos')::bigint OR NEW.forma_pagamento IS DISTINCT FROM r.forma_pagamento THEN RAISE EXCEPTION 'Obrigacao diverge do contrato' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (NEW.reserva_id,NEW.numero,NEW.vencimento_em,NEW.cancelamento_em) IS DISTINCT FROM (OLD.reserva_id,OLD.numero,OLD.vencimento_em,OLD.cancelamento_em) THEN RAISE EXCEPTION 'Cronograma emitido imutavel' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_obrigacao_reserva BEFORE INSERT OR UPDATE ON obrigacoes_reserva FOR EACH ROW EXECUTE FUNCTION proteger_obrigacao_reserva();
CREATE TABLE tarefas_financeiras_reserva(
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,reserva_id INTEGER NOT NULL REFERENCES reservas(id),obrigacao_id BIGINT REFERENCES obrigacoes_reserva(id),
 tipo VARCHAR(20) NOT NULL CHECK(tipo IN ('cancelar_cobranca','reembolso','repasse','emitir_parcela')),chave VARCHAR(150) NOT NULL UNIQUE,
 estado VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK(estado IN ('pendente','processando','concluida','erro','manual')),tentativas INTEGER NOT NULL DEFAULT 0,erro_codigo VARCHAR(150),proxima_tentativa_em TIMESTAMPTZ,
 criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),atualizado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
