ALTER TABLE autorizacoes_pagamento_reserva ADD COLUMN ip_pagador INET;
CREATE FUNCTION validar_ativacao_cronograma() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE; entrada obrigacoes_reserva%ROWTYPE;
BEGIN
 SELECT * INTO r FROM reservas WHERE id=NEW.reserva_id;
 SELECT * INTO entrada FROM obrigacoes_reserva WHERE reserva_id=NEW.reserva_id AND numero=0;
 IF r.modalidade<>'entrada_parcelamento' OR entrada.estado<>'paga' OR entrada.pago_centavos<>entrada.total_centavos OR entrada.pago_em IS DISTINCT FROM NEW.referencia_entrada_em
 OR NEW.quantidade_parcelas IS DISTINCT FROM (r.precificacao_detalhes->>'quantidade_parcelas_saldo')::integer OR NEW.limite_ultima_parcela IS DISTINCT FROM (r.precificacao_detalhes->>'limite_ultima_parcela')::timestamptz
 THEN RAISE EXCEPTION 'Cronograma exige entrada confirmada e contrato congelado' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_ativacao_cronograma BEFORE INSERT ON cronogramas_reserva FOR EACH ROW EXECUTE FUNCTION validar_ativacao_cronograma();
CREATE FUNCTION validar_cancelamento_contratual() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.schema_financeiro>=2 AND NEW.status_reserva='cancelada' AND OLD.status_reserva<>'cancelada' THEN
  IF NEW.tipo_cancelamento IS NULL THEN RAISE EXCEPTION 'Cancelamento exige politica explicita' USING ERRCODE='23514'; END IF;
  IF NEW.modalidade='entrada_parcelamento' AND NEW.entrada_confirmada_em IS NOT NULL AND NEW.tipo_cancelamento='voluntario' THEN RAISE EXCEPTION 'Reserva parcelada paga nao admite cancelamento voluntario' USING ERRCODE='23514'; END IF;
  IF NEW.tipo_cancelamento='administrativo_excepcional' AND NOT EXISTS(SELECT 1 FROM cancelamentos_administrativos_reserva WHERE reserva_id=NEW.id) THEN RAISE EXCEPTION 'Excecao administrativa exige politica auditada' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_cancelamento_contratual BEFORE UPDATE OF status_reserva ON reservas FOR EACH ROW EXECUTE FUNCTION validar_cancelamento_contratual();
