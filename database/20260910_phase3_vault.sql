CREATE TABLE asaas_subconta_credenciais (
 conta_gateway VARCHAR(120) NOT NULL, asaas_account_id VARCHAR(120) NOT NULL,
 ambiente VARCHAR(10) NOT NULL CHECK(ambiente='sandbox'),
 segredo_cifrado TEXT NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(ambiente,conta_gateway,asaas_account_id)
);
