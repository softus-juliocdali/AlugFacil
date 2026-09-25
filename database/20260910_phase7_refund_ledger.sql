ALTER TABLE reembolsos_reservas ADD COLUMN operacao_id BIGINT REFERENCES operacoes_financeiras(id);
CREATE TABLE itens_estorno_asaas(
 asaas_payment_id VARCHAR(100) NOT NULL,identidade CHAR(64) NOT NULL,valor_centavos BIGINT NOT NULL CHECK(valor_centavos>0),estado VARCHAR(30) NOT NULL,descricao TEXT,data_externa VARCHAR(50),comprovante_url TEXT,
 observado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(asaas_payment_id,identidade)
);
