ALTER TABLE repasses_reservas DROP CONSTRAINT repasses_reservas_check;
ALTER TABLE repasses_reservas ADD CONSTRAINT repasse_direito_nao_negativo CHECK(valor_repasse_centavos BETWEEN 0 AND valor_reserva_centavos);
CREATE FUNCTION validar_direito_repasse() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE;
BEGIN
 SELECT * INTO r FROM reservas WHERE id=NEW.reserva_id;
 IF r.schema_financeiro>=2 AND (NEW.proprietario_id IS DISTINCT FROM r.proprietario_id OR NEW.valor_reserva_centavos IS DISTINCT FROM r.valor_hospedagem_centavos OR NEW.valor_repasse_centavos IS DISTINCT FROM r.valor_liquido_proprietario_centavos)
 THEN RAISE EXCEPTION 'Repasse diverge do direito contratual' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validar_direito_repasse BEFORE INSERT OR UPDATE ON repasses_reservas FOR EACH ROW EXECUTE FUNCTION validar_direito_repasse();
