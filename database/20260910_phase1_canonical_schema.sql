-- Executed transactionally by migrate-financial-phase1.php AFTER a verified local backup.
-- Existing owner/financial rows are preserved, not renamed or deleted.
CREATE TABLE proprietario_cadastro (
 id BIGSERIAL PRIMARY KEY,
 proprietario_id INTEGER NOT NULL UNIQUE REFERENCES proprietarios(id) ON DELETE RESTRICT,
 tipo_pessoa VARCHAR(2) CHECK(tipo_pessoa IN ('PF','PJ')),
 cpf_cnpj VARCHAR(14), nome_razao_social VARCHAR(150), nome_fantasia VARCHAR(150),
 data_nascimento DATE, tipo_empresa VARCHAR(20), renda_faturamento_mensal_centavos BIGINT,
 telefone VARCHAR(20), celular VARCHAR(20), email_financeiro VARCHAR(160),
 cep VARCHAR(8), endereco VARCHAR(180), numero VARCHAR(20), complemento VARCHAR(100),
 bairro VARCHAR(100), cidade VARCHAR(100), estado CHAR(2),
 dados_completos BOOLEAN NOT NULL DEFAULT FALSE,
 situacao VARCHAR(20) NOT NULL DEFAULT 'incompleto' CHECK(situacao IN ('incompleto','conflito','validado')),
 versao INTEGER NOT NULL DEFAULT 1 CHECK(versao > 0),
 validado_em TIMESTAMPTZ, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT cadastro_documento CHECK(cpf_cnpj IS NULL OR
   (tipo_pessoa='PF' AND cpf_cnpj ~ '^[0-9]{11}$') OR (tipo_pessoa='PJ' AND cpf_cnpj ~ '^[0-9]{14}$')),
 CONSTRAINT cadastro_completo CHECK(NOT dados_completos OR
   (situacao='validado' AND tipo_pessoa IS NOT NULL AND cpf_cnpj IS NOT NULL AND
    nome_razao_social IS NOT NULL AND celular IS NOT NULL AND email_financeiro IS NOT NULL AND
    cep IS NOT NULL AND endereco IS NOT NULL AND numero IS NOT NULL AND bairro IS NOT NULL AND
    cidade IS NOT NULL AND estado IS NOT NULL AND renda_faturamento_mensal_centavos > 0))
);
CREATE UNIQUE INDEX cadastro_documento_unico ON proprietario_cadastro(cpf_cnpj) WHERE cpf_cnpj IS NOT NULL;

CREATE TABLE proprietario_cadastro_conflitos (
 id BIGSERIAL PRIMARY KEY,
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 campo VARCHAR(60) NOT NULL,
 fontes JSONB NOT NULL,
 detectado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolvido_em TIMESTAMPTZ, resolvido_por INTEGER REFERENCES usuarios(id),
 justificativa VARCHAR(500),
 UNIQUE(proprietario_id,campo)
);
COMMENT ON COLUMN proprietario_cadastro_conflitos.fontes IS 'Dados pessoais originais restritos; relatorios e logs exibem somente IDs/campos.';
COMMENT ON TABLE proprietario_dados_financeiros IS 'Legado preservado; fonte cadastral oficial: proprietario_cadastro. Nao gravar novos cadastros nesta tabela.';
COMMENT ON COLUMN proprietarios.cpf IS 'Legado preservado; fonte oficial PF/PJ: proprietario_cadastro.cpf_cnpj.';

CREATE TABLE financeiro_schema_versions (
 versao VARCHAR(80) PRIMARY KEY, sha256 CHAR(64) NOT NULL,
 aplicada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Same payment domain on aggregate and payment rows. A pending refund is not a completed refund.
ALTER TABLE reservas ALTER COLUMN status_pagamento TYPE VARCHAR(40);
ALTER TABLE pagamentos ALTER COLUMN status_pagamento TYPE VARCHAR(40);
ALTER TABLE reservas DROP CONSTRAINT chk_reservas_status_pagamento;
ALTER TABLE pagamentos DROP CONSTRAINT chk_pagamentos_status;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status_pagamento CHECK(status_pagamento IN
 ('pendente','pago','cancelado','reembolso_processando','parcialmente_estornado','estornado'));
ALTER TABLE pagamentos ADD CONSTRAINT chk_pagamentos_status CHECK(status_pagamento IN
 ('pendente','pago','cancelado','reembolso_processando','parcialmente_estornado','estornado'));
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status_repasse CHECK(status_repasse IS NULL OR status_repasse IN
 ('aguardando_pagamento','aguardando_liberacao','liberado_para_repasse','processando','concluido','falhou','conciliacao_manual','cancelado'));
COMMENT ON COLUMN repasses_reservas.status_local IS 'Fonte oficial do repasse; reservas.status_repasse e projecao reconstruivel. conciliacao_manual inclui resultado externo desconhecido e proibe retry cego.';
COMMENT ON COLUMN reembolsos_reservas.status_local IS 'solicitado -> processando -> concluido. falhou e recusa comprovada; conciliacao_manual e resultado desconhecido, requer consulta antes de retry.';
COMMENT ON COLUMN asaas_subcontas.status_local IS 'Provisionamento/KYC local. Estado canonico de aprovacao: aprovada (nunca ativa); nao comprova liquidacao ou saldo disponivel.';

CREATE FUNCTION projetar_status_repasse() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE reservas SET status_repasse=NEW.status_local WHERE id=NEW.reserva_id;
 RETURN NEW;
END $$;
CREATE TRIGGER projetar_status_repasse AFTER INSERT OR UPDATE OF status_local ON repasses_reservas
 FOR EACH ROW EXECUTE FUNCTION projetar_status_repasse();
UPDATE reservas r SET status_repasse=p.status_local FROM repasses_reservas p WHERE p.reserva_id=r.id;
