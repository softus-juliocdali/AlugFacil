BEGIN;

CREATE TABLE IF NOT EXISTS favoritos_chacaras (
    usuario_id INTEGER NOT NULL,
    chacara_id INTEGER NOT NULL,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, chacara_id),
    CONSTRAINT fk_favoritos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_favoritos_chacara
        FOREIGN KEY (chacara_id) REFERENCES chacaras(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_favoritos_chacaras_chacara
    ON favoritos_chacaras (chacara_id);

COMMIT;
