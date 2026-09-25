ALTER TABLE configuracoes_mensalidades_anuncios ADD COLUMN ativa BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE configuracoes_mensalidades_anuncios ADD COLUMN versao INTEGER NOT NULL DEFAULT 1 CHECK(versao>0);
COMMENT ON COLUMN configuracoes_mensalidades_anuncios.valor_padrao_centavos IS 'Valor GLOBAL para novas obrigacoes; nome legado preservado por compatibilidade. Nao permite preco por imovel.';
CREATE TABLE configuracoes_comerciais_imoveis (
 chacara_id INTEGER PRIMARY KEY REFERENCES chacaras(id),
 sem_mensalidade BOOLEAN NOT NULL DEFAULT FALSE,
 comissao_bps INTEGER CHECK(comissao_bps BETWEEN 0 AND 10000),
 versao INTEGER NOT NULL DEFAULT 1 CHECK(versao>0),
 atualizado_por INTEGER REFERENCES usuarios(id), atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade)
 SELECT c.id,COALESCE(NOT m.ativa,FALSE) FROM chacaras c LEFT JOIN mensalidades_anuncios m ON m.chacara_id=c.id;
COMMENT ON COLUMN configuracoes_comerciais_imoveis.comissao_bps IS 'NULL exige configuracao explicita do administrador antes de novo checkout. Nenhuma comissao global e presumida.';
ALTER TABLE afiliados ADD COLUMN percentual_comissao_bps INTEGER NOT NULL DEFAULT 0 CHECK(percentual_comissao_bps BETWEEN 0 AND 10000);
ALTER TABLE afiliados ADD COLUMN versao_comercial INTEGER NOT NULL DEFAULT 1 CHECK(versao_comercial>0);
-- Initial individual conditions preserve the last legacy rate; future affiliates start at 0 until configured.
UPDATE afiliados SET percentual_comissao_bps=COALESCE((SELECT percentual_bps FROM configuracoes_comissao_afiliados WHERE id=1),0);
ALTER TABLE comissoes_afiliados DROP CONSTRAINT chk_comissao_percentual;
ALTER TABLE comissoes_afiliados ADD CONSTRAINT chk_comissao_percentual CHECK(percentual_bps BETWEEN 0 AND 10000);
CREATE TABLE historico_condicoes_comerciais (
 id BIGSERIAL PRIMARY KEY, tipo VARCHAR(20) NOT NULL CHECK(tipo IN ('global','imovel','afiliado','migracao')),
 entidade_id INTEGER, administrador_id INTEGER REFERENCES usuarios(id), motivo TEXT NOT NULL,
 anterior JSONB, nova JSONB NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO historico_condicoes_comerciais(tipo,entidade_id,motivo,nova)
 SELECT 'migracao',id,'Condicao individual inicial copiada da configuracao legada; ledger preservado',jsonb_build_object('percentual_bps',percentual_comissao_bps) FROM afiliados;
CREATE TABLE obrigacoes_mensalidades (
 id BIGSERIAL PRIMARY KEY, chacara_id INTEGER NOT NULL REFERENCES chacaras(id),
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id), vencimento DATE NOT NULL,
 valor_centavos BIGINT NOT NULL CHECK(valor_centavos>=100), versao_global INTEGER NOT NULL,
 versao_imovel INTEGER NOT NULL, afiliado_id INTEGER REFERENCES afiliados(id),
 percentual_afiliado_bps INTEGER NOT NULL CHECK(percentual_afiliado_bps BETWEEN 0 AND 10000),
 comissao_afiliado_centavos BIGINT NOT NULL CHECK(comissao_afiliado_centavos>=0),
 versao_afiliado INTEGER, forma_pagamento VARCHAR(15) CHECK(forma_pagamento IN ('PIX','CREDIT_CARD')),
 estado VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK(estado IN ('pendente','paga','cancelada','estornada')),
 asaas_payment_id VARCHAR(120) UNIQUE, invoice_url TEXT, operacao_id BIGINT REFERENCES operacoes_financeiras(id),
 criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(chacara_id,vencimento), CHECK(afiliado_id IS NOT NULL OR (percentual_afiliado_bps=0 AND comissao_afiliado_centavos=0))
);
ALTER TABLE cobrancas_mensalidades ADD COLUMN obrigacao_id BIGINT UNIQUE REFERENCES obrigacoes_mensalidades(id);
CREATE FUNCTION proteger_snapshot_mensalidade() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.chacara_id,NEW.proprietario_id,NEW.vencimento,NEW.valor_centavos,NEW.versao_global,NEW.versao_imovel,NEW.afiliado_id,NEW.percentual_afiliado_bps,NEW.comissao_afiliado_centavos,NEW.versao_afiliado)
 IS DISTINCT FROM (OLD.chacara_id,OLD.proprietario_id,OLD.vencimento,OLD.valor_centavos,OLD.versao_global,OLD.versao_imovel,OLD.afiliado_id,OLD.percentual_afiliado_bps,OLD.comissao_afiliado_centavos,OLD.versao_afiliado)
 THEN RAISE EXCEPTION 'Snapshot de mensalidade imutavel' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER proteger_snapshot_mensalidade BEFORE UPDATE ON obrigacoes_mensalidades FOR EACH ROW EXECUTE FUNCTION proteger_snapshot_mensalidade();
