ALTER TABLE pagamentos ADD COLUMN obrigacao_id BIGINT UNIQUE REFERENCES obrigacoes_reserva(id);
UPDATE pagamentos p SET obrigacao_id=o.id FROM obrigacoes_reserva o WHERE o.asaas_payment_id=p.id_transacao_asaas AND o.reserva_id=p.reserva_id;
DROP INDEX uq_pagamento_ativo_reserva;
CREATE UNIQUE INDEX uq_pagamento_ativo_reserva ON pagamentos(reserva_id) WHERE obrigacao_id IS NULL AND status_pagamento IN ('pendente','pago');
CREATE FUNCTION validar_pagamento_obrigacao() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o obrigacoes_reserva%ROWTYPE; r reservas%ROWTYPE;
BEGIN
 IF NEW.obrigacao_id IS NOT NULL THEN
  SELECT * INTO o FROM obrigacoes_reserva WHERE id=NEW.obrigacao_id;
  SELECT * INTO r FROM reservas WHERE id=o.reserva_id;
  IF o.id IS NULL OR NEW.reserva_id<>o.reserva_id OR NEW.usuario_id<>r.usuario_id OR NEW.valor*100<>o.total_centavos OR NEW.id_transacao_asaas IS DISTINCT FROM o.asaas_payment_id THEN RAISE EXCEPTION 'Pagamento diverge da obrigacao' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_pagamento_obrigacao BEFORE INSERT OR UPDATE ON pagamentos FOR EACH ROW EXECUTE FUNCTION validar_pagamento_obrigacao();
