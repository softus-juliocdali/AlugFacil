ALTER TABLE reservas ADD COLUMN entrada_boleto_emitida_em TIMESTAMPTZ;
CREATE FUNCTION proteger_prazo_entrada_boleto() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.entrada_boleto_emitida_em IS NOT NULL AND
 (NEW.entrada_boleto_emitida_em,NEW.expira_em) IS DISTINCT FROM (OLD.entrada_boleto_emitida_em,OLD.expira_em)
 THEN RAISE EXCEPTION 'Prazo da entrada emitida imutavel' USING ERRCODE='23514'; END IF;
 IF OLD.entrada_boleto_emitida_em IS NULL AND NEW.entrada_boleto_emitida_em IS NOT NULL THEN
  IF OLD.modalidade<>'entrada_parcelamento' OR OLD.forma_pagamento<>'BOLETO' OR OLD.status_reserva<>'aguardando_pagamento'
   OR OLD.expira_em<=clock_timestamp() OR NEW.entrada_boleto_emitida_em>clock_timestamp()
   OR NEW.expira_em IS DISTINCT FROM NEW.entrada_boleto_emitida_em+INTERVAL '1 day'
  THEN RAISE EXCEPTION 'Emissao de entrada fora do prazo' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_prazo_entrada_boleto BEFORE UPDATE ON reservas FOR EACH ROW EXECUTE FUNCTION proteger_prazo_entrada_boleto();
