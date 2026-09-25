ALTER TABLE cobrancas_mensalidades ADD COLUMN afiliado_id_snapshot BIGINT REFERENCES afiliados(id);
ALTER TABLE cobrancas_mensalidades ADD COLUMN percentual_afiliado_bps_snapshot INTEGER CHECK(percentual_afiliado_bps_snapshot BETWEEN 0 AND 10000);
UPDATE cobrancas_mensalidades cm SET
 afiliado_id_snapshot=COALESCE(co.afiliado_id,p.afiliado_id),
 percentual_afiliado_bps_snapshot=COALESCE(co.percentual_bps,a.percentual_comissao_bps,0)
 FROM mensalidades_anuncios m JOIN proprietarios p ON p.id=m.proprietario_id
 LEFT JOIN afiliados a ON a.id=p.afiliado_id
 LEFT JOIN comissoes_afiliados co ON co.mensalidade_id=m.id
 WHERE cm.mensalidade_id=m.id AND (co.id IS NULL OR co.cobranca_mensalidade_id=cm.id);
-- Every historical ledger entry takes precedence over current attribution or percentages.
UPDATE cobrancas_mensalidades cm SET afiliado_id_snapshot=co.afiliado_id,percentual_afiliado_bps_snapshot=co.percentual_bps
 FROM comissoes_afiliados co WHERE co.cobranca_mensalidade_id=cm.id;
CREATE FUNCTION snapshot_afiliado_cobranca() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF (NEW.afiliado_id_snapshot,NEW.percentual_afiliado_bps_snapshot,NEW.valor_centavos,NEW.obrigacao_id)
   IS DISTINCT FROM (OLD.afiliado_id_snapshot,OLD.percentual_afiliado_bps_snapshot,OLD.valor_centavos,OLD.obrigacao_id)
  THEN RAISE EXCEPTION 'Snapshot da cobranca mensal imutavel' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.obrigacao_id IS NOT NULL THEN
  SELECT afiliado_id,percentual_afiliado_bps INTO NEW.afiliado_id_snapshot,NEW.percentual_afiliado_bps_snapshot FROM obrigacoes_mensalidades WHERE id=NEW.obrigacao_id;
 ELSE
  SELECT p.afiliado_id,COALESCE(a.percentual_comissao_bps,0) INTO NEW.afiliado_id_snapshot,NEW.percentual_afiliado_bps_snapshot
   FROM mensalidades_anuncios m JOIN proprietarios p ON p.id=m.proprietario_id LEFT JOIN afiliados a ON a.id=p.afiliado_id WHERE m.id=NEW.mensalidade_id;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER snapshot_afiliado_cobranca BEFORE INSERT OR UPDATE ON cobrancas_mensalidades FOR EACH ROW EXECUTE FUNCTION snapshot_afiliado_cobranca();
