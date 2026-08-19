BEGIN;

CREATE TABLE IF NOT EXISTS proprietario_dados_financeiros (
 id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 proprietario_id INTEGER NOT NULL UNIQUE REFERENCES proprietarios(id) ON DELETE RESTRICT,
 tipo_pessoa VARCHAR(2) NOT NULL CHECK (tipo_pessoa IN ('PF','PJ')),
 cpf_cnpj VARCHAR(14) NOT NULL,
 nome_razao_social VARCHAR(150) NOT NULL,
 nome_fantasia VARCHAR(150), data_nascimento DATE, tipo_empresa VARCHAR(20),
 renda_faturamento_mensal_centavos BIGINT NOT NULL CHECK (renda_faturamento_mensal_centavos > 0),
 telefone VARCHAR(20), celular VARCHAR(20) NOT NULL, email_financeiro VARCHAR(160) NOT NULL,
 cep VARCHAR(8) NOT NULL, endereco VARCHAR(180) NOT NULL, numero VARCHAR(20) NOT NULL,
 complemento VARCHAR(100), bairro VARCHAR(100) NOT NULL, cidade VARCHAR(100) NOT NULL,
 estado CHAR(2) NOT NULL, dados_completos BOOLEAN NOT NULL DEFAULT FALSE,
 validado_em TIMESTAMP, criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (cpf_cnpj ~ '^[0-9]{11}([0-9]{3})?$'),
 CHECK ((tipo_pessoa='PF' AND length(cpf_cnpj)=11 AND data_nascimento IS NOT NULL AND tipo_empresa IS NULL)
     OR (tipo_pessoa='PJ' AND length(cpf_cnpj)=14 AND tipo_empresa IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS aceites_financeiros_proprietarios (
 id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 tipo_aceite VARCHAR(50) NOT NULL, versao_documento VARCHAR(30) NOT NULL,
 texto_hash CHAR(64) NOT NULL, aceito_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ip VARCHAR(45), user_agent_resumido VARCHAR(255), revogado_em TIMESTAMP,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_aceite_financeiro_vigente
 ON aceites_financeiros_proprietarios(proprietario_id,tipo_aceite) WHERE revogado_em IS NULL;

CREATE TABLE IF NOT EXISTS asaas_subcontas (
 id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 ambiente VARCHAR(10) NOT NULL CHECK (ambiente IN ('sandbox','production')),
 modelo VARCHAR(15) NOT NULL DEFAULT 'NON_BAAS' CHECK (modelo='NON_BAAS'),
 asaas_account_id VARCHAR(100) UNIQUE, asaas_wallet_id VARCHAR(100) UNIQUE,
 email_cadastro VARCHAR(160) NOT NULL, status_local VARCHAR(30) NOT NULL,
 status_cadastral_asaas VARCHAR(50), status_operacional_asaas VARCHAR(50),
 request_hash CHAR(64) NOT NULL, solicitacao_id VARCHAR(64) NOT NULL UNIQUE,
 quantidade_tentativas INTEGER NOT NULL DEFAULT 0 CHECK (quantidade_tentativas >= 0),
 ultimo_codigo_erro VARCHAR(80), ultimo_erro_sanitizado VARCHAR(1000),
 solicitada_em TIMESTAMP, criada_em TIMESTAMP, ativada_em TIMESTAMP, aprovada_em TIMESTAMP,
 rejeitada_em TIMESTAMP, bloqueada_em TIMESTAMP, ultima_sincronizacao_em TIMESTAMP,
 proxima_sincronizacao_em TIMESTAMP, criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (status_local IN ('nao_iniciado','dados_incompletos','aguardando_aceite','pronto_para_criacao','criando','criada','aguardando_ativacao','em_analise','aprovada','rejeitada','bloqueada','erro','conciliacao_manual'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_asaas_subconta_proprietario_ambiente ON asaas_subcontas(proprietario_id,ambiente);
CREATE INDEX IF NOT EXISTS idx_asaas_subconta_request_hash ON asaas_subcontas(request_hash);
CREATE INDEX IF NOT EXISTS idx_asaas_subconta_status ON asaas_subcontas(ambiente,status_local,proxima_sincronizacao_em);

CREATE TABLE IF NOT EXISTS historico_asaas_subcontas (
 id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 asaas_subconta_id INTEGER NOT NULL REFERENCES asaas_subcontas(id) ON DELETE RESTRICT,
 proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 status_anterior VARCHAR(30), status_novo VARCHAR(30) NOT NULL,
 origem VARCHAR(20) NOT NULL CHECK (origem IN ('proprietario','administrador','sistema','sincronizacao','conciliacao')),
 motivo VARCHAR(500), codigo_externo VARCHAR(80), administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
 metadados JSONB NOT NULL DEFAULT '{}'::jsonb, criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_historico_asaas_subconta ON historico_asaas_subcontas(asaas_subconta_id,criado_em DESC);

CREATE TABLE IF NOT EXISTS auditoria_dados_financeiros (
 id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY, proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
 usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL, origem VARCHAR(20) NOT NULL,
 campos_alterados JSONB NOT NULL DEFAULT '[]'::jsonb, criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
COMMIT;
