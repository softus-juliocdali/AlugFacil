CREATE UNIQUE INDEX configuracao_financeira_ativa_unica ON configuracoes_financeiras((vigencia_fim IS NULL)) WHERE vigencia_fim IS NULL;
ALTER TABLE cotacoes_reserva ADD COLUMN versao_snapshot INTEGER NOT NULL DEFAULT 1;
ALTER TABLE cotacoes_reserva ADD COLUMN modalidade VARCHAR(25) NOT NULL DEFAULT 'integral' CHECK(modalidade IN ('integral','entrada_parcelamento'));
ALTER TABLE cotacoes_reserva ADD COLUMN cancelada_em TIMESTAMPTZ;
ALTER TABLE cotacoes_reserva DROP CONSTRAINT cotacoes_reserva_forma_pagamento_check;
ALTER TABLE cotacoes_reserva ADD CONSTRAINT cotacao_forma_pagamento CHECK(forma_pagamento IN ('PIX','CREDIT_CARD','BOLETO','PIX_AUTOMATIC'));
ALTER TABLE cotacoes_reserva ADD CONSTRAINT cotacao_modalidade_meio CHECK(versao_snapshot<2 OR (modalidade='integral' AND forma_pagamento IN ('PIX','CREDIT_CARD') AND quantidade_parcelas=1) OR (modalidade='entrada_parcelamento' AND forma_pagamento IN ('CREDIT_CARD','BOLETO','PIX_AUTOMATIC') AND quantidade_parcelas BETWEEN 2 AND 19));
ALTER TABLE reservas ADD COLUMN cotacao_id UUID UNIQUE REFERENCES cotacoes_reserva(id);
ALTER TABLE reservas ADD COLUMN schema_financeiro INTEGER NOT NULL DEFAULT 1;
ALTER TABLE reservas ADD COLUMN modalidade VARCHAR(25) NOT NULL DEFAULT 'integral' CHECK(modalidade IN ('integral','entrada_parcelamento'));
ALTER TABLE reservas ADD CONSTRAINT contrato_exige_cotacao CHECK(schema_financeiro<2 OR cotacao_id IS NOT NULL);
CREATE FUNCTION proteger_cotacao_financeira() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.versao_snapshot>=2 AND (
 (NEW.configuracao_financeira_id,NEW.versao_configuracao,NEW.usuario_id,NEW.chacara_id,NEW.data_inicio,NEW.data_fim,NEW.forma_pagamento,NEW.quantidade_parcelas,NEW.detalhes,NEW.expira_em,NEW.criado_em,NEW.versao_snapshot,NEW.modalidade)
 IS DISTINCT FROM (OLD.configuracao_financeira_id,OLD.versao_configuracao,OLD.usuario_id,OLD.chacara_id,OLD.data_inicio,OLD.data_fim,OLD.forma_pagamento,OLD.quantidade_parcelas,OLD.detalhes,OLD.expira_em,OLD.criado_em,OLD.versao_snapshot,OLD.modalidade)
 OR (OLD.consumida_em IS NOT NULL AND NEW.consumida_em IS DISTINCT FROM OLD.consumida_em)
 OR (OLD.cancelada_em IS NOT NULL AND NEW.cancelada_em IS DISTINCT FROM OLD.cancelada_em))
 THEN RAISE EXCEPTION 'Cotacao financeira imutavel' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_cotacao_financeira BEFORE UPDATE ON cotacoes_reserva FOR EACH ROW EXECUTE FUNCTION proteger_cotacao_financeira();
CREATE FUNCTION validar_contrato_cotacao() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE q cotacoes_reserva%ROWTYPE;
BEGIN
 IF TG_OP='UPDATE' THEN
  IF OLD.schema_financeiro>=2 AND (NEW.usuario_id,NEW.proprietario_id,NEW.chacara_id,NEW.valor_diaria,NEW.valor_total,NEW.quantidade_diarias,NEW.cotacao_id,NEW.schema_financeiro,NEW.modalidade,NEW.precificacao_detalhes,NEW.valor_hospedagem_centavos,NEW.valor_total_cliente_centavos,NEW.valor_liquido_proprietario_centavos,NEW.taxa_plataforma_centavos,NEW.taxa_operacao_pix_snapshot_centavos,NEW.forma_pagamento,NEW.quantidade_parcelas,NEW.data_inicio,NEW.data_fim,NEW.checkin_inicio_em,NEW.cancelamento_permitido_ate,NEW.repasse_liberavel_em)
   IS DISTINCT FROM (OLD.usuario_id,OLD.proprietario_id,OLD.chacara_id,OLD.valor_diaria,OLD.valor_total,OLD.quantidade_diarias,OLD.cotacao_id,OLD.schema_financeiro,OLD.modalidade,OLD.precificacao_detalhes,OLD.valor_hospedagem_centavos,OLD.valor_total_cliente_centavos,OLD.valor_liquido_proprietario_centavos,OLD.taxa_plataforma_centavos,OLD.taxa_operacao_pix_snapshot_centavos,OLD.forma_pagamento,OLD.quantidade_parcelas,OLD.data_inicio,OLD.data_fim,OLD.checkin_inicio_em,OLD.cancelamento_permitido_ate,OLD.repasse_liberavel_em)
  THEN RAISE EXCEPTION 'Snapshot contratual imutavel' USING ERRCODE='23514'; END IF;
 ELSIF NEW.schema_financeiro>=2 THEN
  SELECT * INTO q FROM cotacoes_reserva WHERE id=NEW.cotacao_id FOR UPDATE;
  IF NOT FOUND OR q.versao_snapshot<>2 OR q.usuario_id<>NEW.usuario_id OR q.chacara_id<>NEW.chacara_id OR q.cancelada_em IS NOT NULL OR q.consumida_em IS NOT NULL OR q.expira_em<=clock_timestamp()
   OR q.detalhes IS DISTINCT FROM NEW.precificacao_detalhes OR q.modalidade<>NEW.modalidade OR q.data_inicio<>NEW.data_inicio OR q.data_fim<>NEW.data_fim OR q.forma_pagamento<>NEW.forma_pagamento OR q.quantidade_parcelas<>NEW.quantidade_parcelas
   OR NEW.valor_hospedagem_centavos IS DISTINCT FROM (q.detalhes->>'valor_hospedagem_centavos')::bigint
   OR NEW.valor_total_cliente_centavos IS DISTINCT FROM (q.detalhes->>'total_centavos')::bigint
   OR NEW.valor_liquido_proprietario_centavos IS DISTINCT FROM (q.detalhes->>'direito_proprietario_centavos')::bigint
   OR NEW.taxa_plataforma_centavos IS DISTINCT FROM (q.detalhes->>'comissao_imovel_centavos')::bigint
   OR NEW.taxa_operacao_pix_snapshot_centavos IS DISTINCT FROM (q.detalhes->>'taxa_operacional_centavos')::bigint
   OR NEW.proprietario_id IS DISTINCT FROM (q.detalhes->>'proprietario_id')::bigint
   OR NEW.valor_diaria*100 IS DISTINCT FROM (q.detalhes->>'diaria_hospedagem_centavos')::numeric
   OR NEW.quantidade_diarias IS DISTINCT FROM (q.detalhes->>'quantidade_diarias')::integer
   OR NEW.checkin_inicio_em IS DISTINCT FROM (q.detalhes->>'checkin_inicio_em')::timestamptz
   OR NEW.cancelamento_permitido_ate IS DISTINCT FROM (q.detalhes->>'cancelamento_permitido_ate')::timestamptz
   OR NEW.repasse_liberavel_em IS DISTINCT FROM (q.detalhes->>'repasse_liberavel_em')::timestamptz
   OR NEW.expira_em IS DISTINCT FROM q.expira_em
  THEN RAISE EXCEPTION 'Reserva diverge da cotacao valida' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_contrato_cotacao BEFORE INSERT OR UPDATE ON reservas FOR EACH ROW EXECUTE FUNCTION validar_contrato_cotacao();
