-- Expand the existing mode constraint; keep all previously accepted contracts.
ALTER TABLE cotacoes_reserva DROP CONSTRAINT cotacao_modalidade_meio;
ALTER TABLE cotacoes_reserva ADD CONSTRAINT cotacao_modalidade_meio CHECK(
 versao_snapshot<2 OR
 (modalidade='integral' AND forma_pagamento IN ('PIX','CREDIT_CARD') AND quantidade_parcelas=1) OR
 (modalidade='entrada_parcelamento' AND forma_pagamento IN ('PIX','CREDIT_CARD','BOLETO','PIX_AUTOMATIC') AND quantidade_parcelas BETWEEN 2 AND 19)
);

-- Historical schedules remain immutable. D-5 overrides their nominal tolerance.
CREATE OR REPLACE FUNCTION validar_cancelamento_inadimplencia() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.tipo_cancelamento='inadimplencia' AND NEW.status_reserva='cancelada' AND OLD.status_reserva<>'cancelada' AND NOT EXISTS(
  SELECT 1 FROM cancelamentos_inadimplencia_reserva c JOIN obrigacoes_reserva o ON o.id=c.obrigacao_causadora_id
  WHERE c.reserva_id=NEW.id AND o.reserva_id=NEW.id AND o.numero>0 AND o.pago_centavos<o.total_centavos
  AND LEAST(o.cancelamento_em,(NEW.precificacao_detalhes->>'limite_ultima_parcela')::timestamptz)<=clock_timestamp()
  AND c.limite_em=LEAST(o.cancelamento_em,(NEW.precificacao_detalhes->>'limite_ultima_parcela')::timestamptz))
 THEN RAISE EXCEPTION 'Cancelamento sem parcela inadimplente no limite' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION validar_cancelamento_contratual() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.schema_financeiro>=2 AND NEW.status_reserva='cancelada' AND OLD.status_reserva<>'cancelada' THEN
  IF NEW.tipo_cancelamento IS NULL THEN RAISE EXCEPTION 'Cancelamento exige politica explicita' USING ERRCODE='23514'; END IF;
  IF NEW.modalidade='entrada_parcelamento' AND NEW.tipo_cancelamento='voluntario' THEN RAISE EXCEPTION 'Reserva parcelada nao admite cancelamento voluntario' USING ERRCODE='23514'; END IF;
  IF NEW.tipo_cancelamento='administrativo_excepcional' AND NOT EXISTS(SELECT 1 FROM cancelamentos_administrativos_reserva WHERE reserva_id=NEW.id) THEN RAISE EXCEPTION 'Excecao administrativa exige politica auditada' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
