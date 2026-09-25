ALTER TABLE afiliados ADD COLUMN usuario_id INTEGER UNIQUE REFERENCES usuarios(id) ON DELETE RESTRICT;
CREATE TABLE historico_vinculos_afiliados (
 id BIGSERIAL PRIMARY KEY, afiliado_id INTEGER NOT NULL REFERENCES afiliados(id) ON DELETE RESTRICT,
 usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
 evento VARCHAR(30) NOT NULL CHECK(evento='vinculo_comprovado'),
 criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
COMMENT ON COLUMN afiliados.usuario_id IS 'Vinculo imutavel mediante prova de posse das duas identidades; nunca inferido por email.';
ALTER TABLE chacaras ADD CONSTRAINT uq_chacaras_id_proprietario UNIQUE(id,proprietario_id);
ALTER TABLE reservas ADD CONSTRAINT fk_reserva_dono_imovel FOREIGN KEY(chacara_id,proprietario_id) REFERENCES chacaras(id,proprietario_id);

CREATE FUNCTION autorizar_identidade_reserva() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dono INTEGER; identidade RECORD;
BEGIN
 SELECT p.usuario_id INTO dono FROM chacaras c JOIN proprietarios p ON p.id=c.proprietario_id WHERE c.id=NEW.chacara_id;
 SELECT status,tipo_usuario INTO identidade FROM usuarios WHERE id=NEW.usuario_id;
 IF dono IS NULL OR identidade.status IS DISTINCT FROM 'ativo' OR identidade.tipo_usuario NOT IN ('cliente','proprietario','admin') THEN
   RAISE EXCEPTION 'Identidade sem capacidade de reservar' USING ERRCODE='42501';
 END IF;
 IF dono=NEW.usuario_id THEN RAISE EXCEPTION 'Nao e permitido reservar o proprio imovel' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER autorizar_identidade_reserva BEFORE INSERT OR UPDATE OF usuario_id,chacara_id,proprietario_id ON reservas
 FOR EACH ROW EXECUTE FUNCTION autorizar_identidade_reserva();
