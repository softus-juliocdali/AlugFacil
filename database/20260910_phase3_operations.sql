CREATE TABLE operacoes_financeiras (
 id BIGSERIAL PRIMARY KEY,
 ambiente VARCHAR(10) NOT NULL CHECK(ambiente='sandbox'),
 conta_gateway VARCHAR(120) NOT NULL, instancia VARCHAR(64) NOT NULL,
 tipo VARCHAR(30) NOT NULL CHECK(tipo IN ('customer','subconta','cobranca','checkout','assinatura','refund','split','escrow','transferencia','cancelar_cobranca','cancelar_assinatura','cancelar_checkout')),
 entidade VARCHAR(100) NOT NULL, versao_obrigacao INTEGER NOT NULL DEFAULT 1,
 referencia VARCHAR(200) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 payload JSONB NOT NULL, resultado JSONB,
 estado VARCHAR(25) NOT NULL DEFAULT 'pendente' CHECK(estado IN ('pendente','executando','concluida','recusada','desconhecida','cancelada')),
 tentativas INTEGER NOT NULL DEFAULT 0, http_status INTEGER, erro_codigo VARCHAR(80),
 proxima_tentativa_em TIMESTAMPTZ, criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(ambiente,conta_gateway,instancia,tipo,entidade,versao_obrigacao)
);
COMMENT ON COLUMN operacoes_financeiras.payload IS 'Snapshot privado necessario a recuperacao; nao exibir em logs/telas. PAN, CVV, senhas e credenciais proibidos.';
CREATE TABLE tentativas_operacoes_financeiras (
 id BIGSERIAL PRIMARY KEY, operacao_id BIGINT NOT NULL REFERENCES operacoes_financeiras(id),
 acao VARCHAR(30) NOT NULL, resultado VARCHAR(30) NOT NULL, http_status INTEGER,
 criada_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE asaas_customers (
 usuario_id INTEGER NOT NULL REFERENCES usuarios(id), ambiente VARCHAR(10) NOT NULL CHECK(ambiente='sandbox'),
 conta_gateway VARCHAR(120) NOT NULL, asaas_customer_id VARCHAR(120) NOT NULL,
 documento_hash CHAR(64) NOT NULL, operacao_id BIGINT NOT NULL REFERENCES operacoes_financeiras(id),
 PRIMARY KEY(usuario_id,ambiente,conta_gateway), UNIQUE(ambiente,conta_gateway,asaas_customer_id)
);
CREATE TABLE usuario_documentos (
 usuario_id INTEGER PRIMARY KEY REFERENCES usuarios(id), tipo_pessoa VARCHAR(2) NOT NULL CHECK(tipo_pessoa IN ('PF','PJ')),
 cpf_cnpj VARCHAR(14) NOT NULL,
 CHECK((tipo_pessoa='PF' AND cpf_cnpj ~ '^[0-9]{11}$') OR (tipo_pessoa='PJ' AND cpf_cnpj ~ '^[0-9]{14}$')),
 atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE onboarding_fila (
 proprietario_id INTEGER PRIMARY KEY REFERENCES proprietarios(id),
 versao_cadastro INTEGER NOT NULL, estado VARCHAR(25) NOT NULL DEFAULT 'pendente'
   CHECK(estado IN ('pendente','processando','aguardando_aceite','aguardando_asaas','concluido','conciliacao_manual')),
 tentativas INTEGER NOT NULL DEFAULT 0, erro_codigo VARCHAR(80),
 proxima_tentativa_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE FUNCTION enfileirar_onboarding_cadastro() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.dados_completos AND NEW.situacao='validado' THEN
  INSERT INTO onboarding_fila(proprietario_id,versao_cadastro) VALUES(NEW.proprietario_id,NEW.versao)
  ON CONFLICT(proprietario_id) DO UPDATE SET versao_cadastro=EXCLUDED.versao_cadastro,
   estado=CASE WHEN onboarding_fila.estado='conciliacao_manual' THEN onboarding_fila.estado ELSE 'pendente' END,
   proxima_tentativa_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER enfileirar_onboarding_cadastro AFTER INSERT OR UPDATE ON proprietario_cadastro
 FOR EACH ROW EXECUTE FUNCTION enfileirar_onboarding_cadastro();
INSERT INTO onboarding_fila(proprietario_id,versao_cadastro) SELECT proprietario_id,versao FROM proprietario_cadastro WHERE dados_completos AND situacao='validado';
