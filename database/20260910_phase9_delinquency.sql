ALTER TABLE obrigacoes_reserva ADD COLUMN inadimplencia_estado VARCHAR(30) NOT NULL DEFAULT 'pendente' CHECK(inadimplencia_estado IN ('pendente','regular','atrasada','tolerancia','cancelamento_definitivo'));
CREATE TABLE historico_inadimplencia_reserva(
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,obrigacao_id BIGINT NOT NULL REFERENCES obrigacoes_reserva(id),reserva_id INTEGER NOT NULL REFERENCES reservas(id),
 estado VARCHAR(30) NOT NULL,pago_centavos BIGINT NOT NULL,limite_em TIMESTAMPTZ,observado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),UNIQUE(obrigacao_id,estado)
);
CREATE TABLE cancelamentos_inadimplencia_reserva(
 reserva_id INTEGER PRIMARY KEY REFERENCES reservas(id),obrigacao_causadora_id BIGINT NOT NULL REFERENCES obrigacoes_reserva(id),
 limite_em TIMESTAMPTZ NOT NULL,decidido_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),ultima_parcela BOOLEAN NOT NULL,
 recebido_centavos BIGINT NOT NULL,politica VARCHAR(50) NOT NULL DEFAULT 'SEM_ESTORNO_PRESERVA_DIREITOS_CONFIRMADOS'
);
CREATE FUNCTION validar_cancelamento_inadimplencia() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.tipo_cancelamento='inadimplencia' AND NEW.status_reserva='cancelada' AND OLD.status_reserva<>'cancelada' AND NOT EXISTS(
  SELECT 1 FROM cancelamentos_inadimplencia_reserva c JOIN obrigacoes_reserva o ON o.id=c.obrigacao_causadora_id
  WHERE c.reserva_id=NEW.id AND o.reserva_id=NEW.id AND o.numero>0 AND o.pago_centavos<o.total_centavos AND o.cancelamento_em<=clock_timestamp() AND c.limite_em=o.cancelamento_em)
 THEN RAISE EXCEPTION 'Cancelamento sem parcela inadimplente no limite' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_cancelamento_inadimplencia BEFORE UPDATE OF status_reserva ON reservas FOR EACH ROW EXECUTE FUNCTION validar_cancelamento_inadimplencia();
