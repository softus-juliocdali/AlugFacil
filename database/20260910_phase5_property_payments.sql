CREATE TABLE configuracoes_parcelamento (
 id SMALLINT PRIMARY KEY CHECK(id=1), entrada_minima_bps INTEGER, entrada_maxima_bps INTEGER,
 versao INTEGER NOT NULL DEFAULT 1 CHECK(versao>0), atualizado_por INTEGER REFERENCES usuarios(id),
 atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK((entrada_minima_bps IS NULL AND entrada_maxima_bps IS NULL) OR (entrada_minima_bps IS NOT NULL AND entrada_maxima_bps IS NOT NULL AND entrada_minima_bps>0 AND entrada_maxima_bps<10000 AND entrada_minima_bps<=entrada_maxima_bps))
);
INSERT INTO configuracoes_parcelamento(id) VALUES(1);
ALTER TABLE configuracoes_comerciais_imoveis ADD COLUMN aceita_parcelamento BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE configuracoes_comerciais_imoveis ADD COLUMN entrada_bps INTEGER;
ALTER TABLE configuracoes_comerciais_imoveis ADD CONSTRAINT entrada_configurada CHECK((aceita_parcelamento AND entrada_bps IS NOT NULL AND entrada_bps>0 AND entrada_bps<10000) OR (NOT aceita_parcelamento AND entrada_bps IS NULL));
CREATE TABLE historico_condicoes_pagamento (
 id BIGSERIAL PRIMARY KEY, chacara_id INTEGER REFERENCES chacaras(id), usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
 anterior JSONB, nova JSONB NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
