BEGIN;

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expira_em TIMESTAMP NOT NULL,
    usado_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_password_resets_usuario
    ON password_resets (usuario_id);
CREATE INDEX IF NOT EXISTS idx_password_resets_expiracao
    ON password_resets (expira_em);

COMMIT;
