-- ============================================================================
-- Alug Facil - atualizacao incremental do banco de producao
-- Banco autorizado: alugfaciln_sistema | PostgreSQL 13+
-- Gerado a partir da auditoria remota e do schema funcional alugfacil_dev.
--
-- Propriedades:
--   * transacional: qualquer erro desfaz todo o lote;
--   * aditivo/idempotente: nao usa DROP TABLE, TRUNCATE ou DELETE;
--   * preserva usuarios, proprietarios, chacaras, reservas e pagamentos;
--   * nao executa arquivos de migration nem guardas exclusivos de desenvolvimento.
-- ============================================================================

BEGIN;
SET LOCAL lock_timeout = '15s';
SET LOCAL statement_timeout = '0';

-- Impede execucao acidental no banco errado ou sobre um baseline incompatível.
DO $$
BEGIN
    IF current_database() <> 'alugfaciln_sistema' THEN
        RAISE EXCEPTION 'Banco incorreto: esperado alugfaciln_sistema; conectado em %.', current_database();
    END IF;
    IF current_setting('server_version_num')::integer < 130000 THEN
        RAISE EXCEPTION 'PostgreSQL 13 ou superior e obrigatorio.';
    END IF;
    IF to_regclass('public.usuarios') IS NULL
       OR to_regclass('public.proprietarios') IS NULL
       OR to_regclass('public.chacaras') IS NULL
       OR to_regclass('public.reservas') IS NULL
       OR to_regclass('public.pagamentos') IS NULL THEN
        RAISE EXCEPTION 'Baseline incompatível: uma ou mais tabelas principais estao ausentes.';
    END IF;
END
$$;

-- Valida conflitos de tipo somente para colunas que ja existam. Colunas ausentes
-- serao adicionadas nas secoes seguintes; tipos divergentes interrompem o lote.
DO $$
DECLARE
    conflitos text;
BEGIN
    WITH esperado(tabela,coluna,tipo) AS (
        VALUES
            ('usuarios','id','int4'),
            ('proprietarios','id','int4'),
            ('chacaras','id','int4'),
            ('reservas','id','int4'),
            ('pagamentos','id','int4'),
            ('reservas','valor_total_cliente_centavos','int8'),
            ('reservas','valor_hospedagem_centavos','int8'),
            ('reservas','valor_liquido_proprietario_centavos','int8'),
            ('reservas','precificacao_detalhes','jsonb'),
            ('reservas','checkin_inicio_em','timestamptz'),
            ('reservas','cancelamento_permitido_ate','timestamptz'),
            ('reservas','repasse_liberavel_em','timestamptz'),
            ('chacaras','checkin_hora_inicial','time'),
            ('chacaras','checkin_hora_final','time'),
            ('chacaras','checkout_hora_inicial','time'),
            ('chacaras','checkout_hora_final','time')
    )
    SELECT string_agg(
        format('%s.%s: atual=%s esperado=%s',e.tabela,e.coluna,c.udt_name,e.tipo),
        '; ' ORDER BY e.tabela,e.coluna
    ) INTO conflitos
    FROM esperado e
    JOIN information_schema.columns c
      ON c.table_schema='public' AND c.table_name=e.tabela AND c.column_name=e.coluna
    WHERE c.udt_name <> e.tipo;

    IF conflitos IS NOT NULL THEN
        RAISE EXCEPTION 'Conflitos de tipo detectados: %', conflitos;
    END IF;
END
$$;

-- --------------------------------------------------------------------------
-- 1. Compatibilidade cadastral e fluxo de aprovacao
-- --------------------------------------------------------------------------
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(11);
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS motivo_status TEXT;
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS status_decidido_em TIMESTAMP;
ALTER TABLE proprietarios ADD COLUMN IF NOT EXISTS status_decidido_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM proprietarios
        WHERE cpf IS NOT NULL
        GROUP BY cpf HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Conflito: existem CPFs duplicados em proprietarios.';
    END IF;
END
$$;

ALTER TABLE proprietarios DROP CONSTRAINT IF EXISTS chk_proprietarios_cpf;
ALTER TABLE proprietarios ADD CONSTRAINT chk_proprietarios_cpf
    CHECK (cpf IS NULL OR cpf ~ '^[0-9]{11}$') NOT VALID;
ALTER TABLE proprietarios VALIDATE CONSTRAINT chk_proprietarios_cpf;
CREATE UNIQUE INDEX IF NOT EXISTS uq_proprietarios_cpf
    ON proprietarios (cpf) WHERE cpf IS NOT NULL;

ALTER TABLE proprietarios DROP CONSTRAINT IF EXISTS chk_proprietarios_status;
ALTER TABLE proprietarios ADD CONSTRAINT chk_proprietarios_status
    CHECK (status IN ('pendente','ativo','rejeitado','bloqueado')) NOT VALID;
ALTER TABLE proprietarios VALIDATE CONSTRAINT chk_proprietarios_status;

ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS tipo_imovel VARCHAR(20) NOT NULL DEFAULT 'chacara';
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_aprovacao VARCHAR(15);
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_operacional VARCHAR(15);
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS motivo_status TEXT;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_decidido_em TIMESTAMP;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS status_decidido_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

UPDATE chacaras
SET status_aprovacao = CASE
        WHEN status = 'pendente' THEN 'pendente'
        WHEN status = 'bloqueada' THEN 'bloqueada'
        ELSE 'aprovada'
    END,
    status_operacional = CASE
        WHEN status = 'disponivel' THEN 'disponivel'
        ELSE 'indisponivel'
    END
WHERE status_aprovacao IS NULL OR status_operacional IS NULL;

ALTER TABLE chacaras ALTER COLUMN status_aprovacao SET DEFAULT 'pendente';
ALTER TABLE chacaras ALTER COLUMN status_aprovacao SET NOT NULL;
ALTER TABLE chacaras ALTER COLUMN status_operacional SET DEFAULT 'indisponivel';
ALTER TABLE chacaras ALTER COLUMN status_operacional SET NOT NULL;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_tipo_imovel;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_tipo_imovel
    CHECK (tipo_imovel IN ('chacara','sitio','area_lazer')) NOT VALID;
ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_tipo_imovel;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_status_aprovacao;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_status_aprovacao
    CHECK (status_aprovacao IN ('pendente','aprovada','rejeitada','bloqueada')) NOT VALID;
ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_status_aprovacao;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_status_operacional;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_status_operacional
    CHECK (status_operacional IN ('disponivel','indisponivel')) NOT VALID;
ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_status_operacional;

CREATE TABLE IF NOT EXISTS historico_status_proprietarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(15) NOT NULL,
    status_novo VARCHAR(15) NOT NULL,
    motivo TEXT,
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS historico_status_chacaras (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chacara_id INTEGER NOT NULL REFERENCES chacaras(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(15) NOT NULL,
    status_novo VARCHAR(15) NOT NULL,
    motivo TEXT,
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_chacaras_publicacao
    ON chacaras (status_aprovacao,status_operacional,proprietario_id);
CREATE INDEX IF NOT EXISTS idx_historico_proprietarios_entidade
    ON historico_status_proprietarios (proprietario_id,criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_historico_chacaras_entidade
    ON historico_status_chacaras (chacara_id,criado_em DESC);

-- --------------------------------------------------------------------------
-- 2. Ciclo de vida das reservas
-- --------------------------------------------------------------------------
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS expira_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_por_tipo VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelada_por_id INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS motivo_cancelamento VARCHAR(500);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelamento_solicitado_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS inicio_real_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS finalizada_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS ultima_transicao_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS versao_status INTEGER NOT NULL DEFAULT 1;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS divergencia_pagamento BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE reservas DROP CONSTRAINT IF EXISTS chk_reservas_status;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status CHECK (status_reserva IN (
    'solicitada','aguardando_pagamento','pagamento_confirmado','confirmada',
    'em_andamento','finalizada','cancelamento_solicitado','cancelada',
    'expirada','estornada','disputa'
)) NOT VALID;
ALTER TABLE reservas VALIDATE CONSTRAINT chk_reservas_status;

CREATE TABLE IF NOT EXISTS historico_status_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id INTEGER NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    motivo VARCHAR(500),
    origem VARCHAR(20) NOT NULL,
    responsavel_tipo VARCHAR(20) NOT NULL,
    responsavel_id INTEGER,
    metadados JSONB,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pagamentos
        WHERE status_pagamento IN ('pendente','pago')
        GROUP BY reserva_id HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Conflito: existe reserva com mais de um pagamento ativo.';
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS idx_reservas_expiracao
    ON reservas (expira_em,id) WHERE status_reserva = 'aguardando_pagamento';
CREATE INDEX IF NOT EXISTS idx_reservas_bloqueio_datas
    ON reservas (chacara_id,data_inicio,data_fim,status_reserva);
CREATE INDEX IF NOT EXISTS idx_historico_reserva
    ON historico_status_reservas (reserva_id,criado_em DESC,id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pagamento_ativo_reserva
    ON pagamentos (reserva_id) WHERE status_pagamento IN ('pendente','pago');

-- --------------------------------------------------------------------------
-- 3. Webhooks Asaas
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asaas_webhook_eventos (
    id BIGSERIAL PRIMARY KEY,
    asaas_event_id VARCHAR(120) NOT NULL,
    tipo_evento VARCHAR(120) NOT NULL,
    tipo_recurso VARCHAR(40) NOT NULL DEFAULT 'payment',
    asaas_payment_id VARCHAR(120),
    external_reference VARCHAR(255),
    payload JSONB NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status_processamento VARCHAR(20) NOT NULL DEFAULT 'recebido',
    quantidade_tentativas INTEGER NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(1000),
    recebido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em TIMESTAMP,
    processado_em TIMESTAMP,
    proxima_tentativa_em TIMESTAMP,
    revisao_motivo VARCHAR(500),
    revisao_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    revisao_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asaas_webhook_event_id UNIQUE (asaas_event_id),
    CONSTRAINT ck_asaas_webhook_status CHECK (status_processamento IN ('recebido','processando','processado','ignorado','divergente','erro')),
    CONSTRAINT ck_asaas_webhook_tentativas CHECK (quantidade_tentativas >= 0),
    CONSTRAINT ck_asaas_webhook_hash CHECK (payload_hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_fila
    ON asaas_webhook_eventos (status_processamento,proxima_tentativa_em,recebido_em);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_payment
    ON asaas_webhook_eventos (asaas_payment_id) WHERE asaas_payment_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_recebido
    ON asaas_webhook_eventos (recebido_em DESC);

-- --------------------------------------------------------------------------
-- 4. Precificacao e valores em centavos
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracoes_financeiras (
    id BIGSERIAL PRIMARY KEY,
    taxa_plataforma_percentual_bps INTEGER NOT NULL CHECK (taxa_plataforma_percentual_bps BETWEEN 0 AND 9999),
    taxa_plataforma_fixa_centavos BIGINT NOT NULL CHECK (taxa_plataforma_fixa_centavos >= 0),
    precificacao_ativa BOOLEAN NOT NULL DEFAULT FALSE,
    moeda CHAR(3) NOT NULL DEFAULT 'BRL' CHECK (moeda = 'BRL'),
    versao INTEGER NOT NULL UNIQUE CHECK (versao > 0),
    vigencia_inicio TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vigencia_fim TIMESTAMPTZ,
    criado_por_admin_id INTEGER REFERENCES usuarios(id),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (vigencia_fim IS NULL OR vigencia_fim > vigencia_inicio)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_configuracao_financeira_vigente
    ON configuracoes_financeiras ((1)) WHERE vigencia_fim IS NULL;

CREATE TABLE IF NOT EXISTS taxas_meios_pagamento (
    id BIGSERIAL PRIMARY KEY,
    configuracao_financeira_id BIGINT NOT NULL REFERENCES configuracoes_financeiras(id),
    forma_pagamento VARCHAR(20) NOT NULL CHECK (forma_pagamento IN ('PIX','CREDIT_CARD','BOLETO')),
    quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
    percentual_gateway_bps INTEGER NOT NULL CHECK (percentual_gateway_bps BETWEEN 0 AND 9999),
    taxa_fixa_gateway_centavos BIGINT NOT NULL CHECK (taxa_fixa_gateway_centavos >= 0),
    margem_seguranca_bps INTEGER NOT NULL DEFAULT 0 CHECK (margem_seguranca_bps BETWEEN 0 AND 9999),
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (configuracao_financeira_id,forma_pagamento,quantidade_parcelas),
    CHECK (forma_pagamento <> 'PIX' OR quantidade_parcelas = 1)
);
CREATE TABLE IF NOT EXISTS historico_configuracoes_financeiras (
    id BIGSERIAL PRIMARY KEY,
    configuracao_anterior JSONB,
    configuracao_nova JSONB NOT NULL,
    administrador_id INTEGER NOT NULL REFERENCES usuarios(id),
    motivo VARCHAR(500) NOT NULL CHECK (length(trim(motivo)) > 0),
    versao INTEGER NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS cotacoes_reserva (
    id UUID PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
    chacara_id INTEGER NOT NULL REFERENCES chacaras(id),
    configuracao_financeira_id BIGINT NOT NULL REFERENCES configuracoes_financeiras(id),
    versao_configuracao INTEGER NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    forma_pagamento VARCHAR(20) NOT NULL CHECK (forma_pagamento IN ('PIX','CREDIT_CARD')),
    quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
    detalhes JSONB NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em TIMESTAMPTZ NOT NULL,
    consumida_em TIMESTAMPTZ,
    CHECK (data_fim > data_inicio),
    CHECK (expira_em > criado_em)
);
CREATE INDEX IF NOT EXISTS idx_cotacoes_reserva_validade
    ON cotacoes_reserva (usuario_id,expira_em) WHERE consumida_em IS NULL;

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_diaria_liquido_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_hospedagem_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_liquido_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_plataforma_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_gateway_estimada_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS valor_total_cliente_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS plataforma_percentual_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS plataforma_fixa_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS gateway_percentual_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS gateway_fixa_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS margem_seguranca_bps INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS forma_pagamento VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS quantidade_parcelas INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS versao_precificacao INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS origem_precificacao VARCHAR(10);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS precificacao_detalhes JSONB;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS precificado_em TIMESTAMPTZ;

-- Backfill preservador: converte os valores decimais historicos para centavos.
UPDATE reservas SET
    valor_diaria_liquido_proprietario_centavos = ROUND(valor_diaria * 100)::BIGINT,
    valor_hospedagem_centavos = ROUND(valor_total * 100)::BIGINT,
    valor_liquido_proprietario_centavos = ROUND(valor_total * 100)::BIGINT,
    taxa_plataforma_centavos = 0,
    taxa_gateway_estimada_centavos = 0,
    valor_total_cliente_centavos = ROUND(valor_total * 100)::BIGINT,
    plataforma_percentual_bps = 0,
    plataforma_fixa_centavos = 0,
    gateway_percentual_bps = 0,
    gateway_fixa_centavos = 0,
    margem_seguranca_bps = 0,
    quantidade_parcelas = 1,
    versao_precificacao = 0,
    origem_precificacao = 'legado',
    precificacao_detalhes = jsonb_build_object('origem','legado'),
    precificado_em = COALESCE(data_reserva,CURRENT_TIMESTAMP)
WHERE valor_total_cliente_centavos IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.reservas'::regclass
          AND conname = 'chk_reservas_snapshot_precos'
    ) THEN
        ALTER TABLE reservas ADD CONSTRAINT chk_reservas_snapshot_precos CHECK (
            valor_diaria_liquido_proprietario_centavos >= 0
            AND valor_hospedagem_centavos >= 0
            AND valor_liquido_proprietario_centavos >= 0
            AND taxa_plataforma_centavos >= 0
            AND taxa_gateway_estimada_centavos >= 0
            AND valor_total_cliente_centavos >= valor_liquido_proprietario_centavos + taxa_plataforma_centavos
        ) NOT VALID;
    END IF;
END
$$;
ALTER TABLE reservas VALIDATE CONSTRAINT chk_reservas_snapshot_precos;

CREATE OR REPLACE FUNCTION proteger_snapshot_financeiro_pago() RETURNS trigger AS $$
BEGIN
    IF OLD.status_pagamento = 'pago' AND ROW(
        NEW.valor_diaria_liquido_proprietario_centavos,NEW.valor_hospedagem_centavos,
        NEW.valor_liquido_proprietario_centavos,NEW.taxa_plataforma_centavos,
        NEW.taxa_gateway_estimada_centavos,NEW.valor_total_cliente_centavos,
        NEW.plataforma_percentual_bps,NEW.plataforma_fixa_centavos,
        NEW.gateway_percentual_bps,NEW.gateway_fixa_centavos,
        NEW.margem_seguranca_bps,NEW.forma_pagamento,
        NEW.quantidade_parcelas,NEW.versao_precificacao
    ) IS DISTINCT FROM ROW(
        OLD.valor_diaria_liquido_proprietario_centavos,OLD.valor_hospedagem_centavos,
        OLD.valor_liquido_proprietario_centavos,OLD.taxa_plataforma_centavos,
        OLD.taxa_gateway_estimada_centavos,OLD.valor_total_cliente_centavos,
        OLD.plataforma_percentual_bps,OLD.plataforma_fixa_centavos,
        OLD.gateway_percentual_bps,OLD.gateway_fixa_centavos,
        OLD.margem_seguranca_bps,OLD.forma_pagamento,
        OLD.quantidade_parcelas,OLD.versao_precificacao
    ) THEN
        RAISE EXCEPTION 'Snapshot financeiro de reserva paga e imutavel';
    END IF;
    RETURN NEW;
END
$$ LANGUAGE plpgsql;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'public.reservas'::regclass
          AND tgname = 'trg_proteger_snapshot_financeiro_pago'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER trg_proteger_snapshot_financeiro_pago
        BEFORE UPDATE ON reservas FOR EACH ROW
        EXECUTE FUNCTION proteger_snapshot_financeiro_pago();
    END IF;
END
$$;

-- --------------------------------------------------------------------------
-- 5. Onboarding financeiro e subcontas Asaas
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proprietario_dados_financeiros (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL UNIQUE REFERENCES proprietarios(id) ON DELETE RESTRICT,
    tipo_pessoa VARCHAR(2) NOT NULL CHECK (tipo_pessoa IN ('PF','PJ')),
    cpf_cnpj VARCHAR(14) NOT NULL,
    nome_razao_social VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150),
    data_nascimento DATE,
    tipo_empresa VARCHAR(20),
    renda_faturamento_mensal_centavos BIGINT NOT NULL CHECK (renda_faturamento_mensal_centavos > 0),
    telefone VARCHAR(20),
    celular VARCHAR(20) NOT NULL,
    email_financeiro VARCHAR(160) NOT NULL,
    cep VARCHAR(8) NOT NULL,
    endereco VARCHAR(180) NOT NULL,
    numero VARCHAR(20) NOT NULL,
    complemento VARCHAR(100),
    bairro VARCHAR(100) NOT NULL,
    cidade VARCHAR(100) NOT NULL,
    estado CHAR(2) NOT NULL,
    dados_completos BOOLEAN NOT NULL DEFAULT FALSE,
    validado_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (cpf_cnpj ~ '^[0-9]{11}([0-9]{3})?$'),
    CHECK ((tipo_pessoa='PF' AND length(cpf_cnpj)=11 AND data_nascimento IS NOT NULL AND tipo_empresa IS NULL)
        OR (tipo_pessoa='PJ' AND length(cpf_cnpj)=14 AND tipo_empresa IS NOT NULL))
);
CREATE TABLE IF NOT EXISTS aceites_financeiros_proprietarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    tipo_aceite VARCHAR(50) NOT NULL,
    versao_documento VARCHAR(100) NOT NULL,
    texto_hash CHAR(64) NOT NULL,
    aceito_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45),
    user_agent_resumido VARCHAR(255),
    revogado_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_aceite_financeiro_vigente
    ON aceites_financeiros_proprietarios (proprietario_id,tipo_aceite)
    WHERE revogado_em IS NULL;

CREATE TABLE IF NOT EXISTS asaas_subcontas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    ambiente VARCHAR(10) NOT NULL CHECK (ambiente IN ('sandbox','production')),
    modelo VARCHAR(15) NOT NULL DEFAULT 'NON_BAAS' CHECK (modelo='NON_BAAS'),
    asaas_account_id VARCHAR(100) UNIQUE,
    asaas_wallet_id VARCHAR(100) UNIQUE,
    email_cadastro VARCHAR(160) NOT NULL,
    status_local VARCHAR(30) NOT NULL,
    status_cadastral_asaas VARCHAR(50),
    status_operacional_asaas VARCHAR(50),
    request_hash CHAR(64) NOT NULL,
    solicitacao_id VARCHAR(64) NOT NULL UNIQUE,
    quantidade_tentativas INTEGER NOT NULL DEFAULT 0 CHECK (quantidade_tentativas >= 0),
    ultimo_codigo_erro VARCHAR(80),
    ultimo_erro_sanitizado VARCHAR(1000),
    solicitada_em TIMESTAMP,
    criada_em TIMESTAMP,
    ativada_em TIMESTAMP,
    aprovada_em TIMESTAMP,
    rejeitada_em TIMESTAMP,
    bloqueada_em TIMESTAMP,
    ultima_sincronizacao_em TIMESTAMP,
    proxima_sincronizacao_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (status_local IN ('nao_iniciado','dados_incompletos','aguardando_aceite','pronto_para_criacao','criando','criada','aguardando_ativacao','em_analise','aprovada','rejeitada','bloqueada','erro','conciliacao_manual'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_asaas_subconta_proprietario_ambiente
    ON asaas_subcontas (proprietario_id,ambiente);
CREATE INDEX IF NOT EXISTS idx_asaas_subconta_request_hash ON asaas_subcontas (request_hash);
CREATE INDEX IF NOT EXISTS idx_asaas_subconta_status
    ON asaas_subcontas (ambiente,status_local,proxima_sincronizacao_em);

CREATE TABLE IF NOT EXISTS historico_asaas_subcontas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    asaas_subconta_id INTEGER NOT NULL REFERENCES asaas_subcontas(id) ON DELETE RESTRICT,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(20) NOT NULL CHECK (origem IN ('proprietario','administrador','sistema','sincronizacao','conciliacao')),
    motivo VARCHAR(500),
    codigo_externo VARCHAR(80),
    administrador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    metadados JSONB NOT NULL DEFAULT '{}'::jsonb,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_historico_asaas_subconta
    ON historico_asaas_subcontas (asaas_subconta_id,criado_em DESC);
CREATE TABLE IF NOT EXISTS auditoria_dados_financeiros (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    origem VARCHAR(20) NOT NULL,
    campos_alterados JSONB NOT NULL DEFAULT '[]'::jsonb,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------------------------
-- 6. Snapshot e historico de split Asaas
-- --------------------------------------------------------------------------
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_proprietario_centavos BIGINT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_modalidade VARCHAR(20);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_wallet_id_hash CHAR(64);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_versao INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_preparado_em TIMESTAMP;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_origem VARCHAR(20) NOT NULL DEFAULT 'legado';
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS split_status_resumo VARCHAR(30) NOT NULL DEFAULT 'sem_split_legado';

CREATE TABLE IF NOT EXISTS asaas_splits (
    id BIGSERIAL PRIMARY KEY,
    reserva_id INTEGER NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
    pagamento_id INTEGER REFERENCES pagamentos(id) ON DELETE RESTRICT,
    proprietario_id INTEGER NOT NULL REFERENCES proprietarios(id) ON DELETE RESTRICT,
    asaas_subconta_id INTEGER NOT NULL REFERENCES asaas_subcontas(id) ON DELETE RESTRICT,
    ambiente VARCHAR(10) NOT NULL CHECK (ambiente IN ('sandbox','production')),
    asaas_payment_id VARCHAR(120),
    wallet_id VARCHAR(100) NOT NULL,
    modalidade_split VARCHAR(20) NOT NULL CHECK (modalidade_split IN ('FIXED_VALUE','TOTAL_FIXED_VALUE')),
    valor_split_centavos BIGINT NOT NULL CHECK (valor_split_centavos > 0),
    quantidade_parcelas INTEGER NOT NULL CHECK (quantidade_parcelas BETWEEN 1 AND 24),
    valor_por_parcela_estimado_centavos BIGINT NOT NULL CHECK (valor_por_parcela_estimado_centavos > 0),
    status_local VARCHAR(30) NOT NULL DEFAULT 'preparado' CHECK (status_local IN ('preparado','enviado','pendente','aguardando_credito','concluido','recusado','cancelado','estornado','divergente','erro','conciliacao_manual')),
    status_asaas VARCHAR(40),
    asaas_split_id VARCHAR(120),
    refusal_reason VARCHAR(500),
    payload_hash CHAR(64) NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    divergencia BOOLEAN NOT NULL DEFAULT FALSE,
    divergencia_motivo VARCHAR(500),
    observacao_revisao VARCHAR(500),
    solicitado_em TIMESTAMP,
    confirmado_em TIMESTAMP,
    concluido_em TIMESTAMP,
    recusado_em TIMESTAMP,
    cancelado_em TIMESTAMP,
    estornado_em TIMESTAMP,
    ultima_sincronizacao_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (pagamento_id,proprietario_id),
    UNIQUE (asaas_payment_id,proprietario_id),
    UNIQUE (asaas_split_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_asaas_split_reserva_proprietario
    ON asaas_splits (reserva_id,proprietario_id);
CREATE INDEX IF NOT EXISTS idx_asaas_split_reserva ON asaas_splits (reserva_id);
CREATE INDEX IF NOT EXISTS idx_asaas_split_pagamento
    ON asaas_splits (pagamento_id) WHERE pagamento_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_asaas_split_proprietario
    ON asaas_splits (proprietario_id,status_local);
CREATE INDEX IF NOT EXISTS idx_asaas_split_status_datas
    ON asaas_splits (status_local,ultima_sincronizacao_em,criado_em);

CREATE TABLE IF NOT EXISTS historico_asaas_splits (
    id BIGSERIAL PRIMARY KEY,
    asaas_split_local_id BIGINT NOT NULL REFERENCES asaas_splits(id) ON DELETE RESTRICT,
    reserva_id INTEGER NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
    pagamento_id INTEGER REFERENCES pagamentos(id) ON DELETE RESTRICT,
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(20) NOT NULL CHECK (origem IN ('sistema','cobranca','webhook','administrador','sincronizacao','conciliacao')),
    tipo_evento VARCHAR(120),
    motivo VARCHAR(500),
    refusal_reason VARCHAR(500),
    asaas_event_id VARCHAR(120),
    metadados JSONB NOT NULL DEFAULT '{}'::jsonb,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_historico_split_evento
    ON historico_asaas_splits (asaas_event_id) WHERE asaas_event_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_historico_split
    ON historico_asaas_splits (asaas_split_local_id,criado_em DESC,id DESC);

CREATE OR REPLACE FUNCTION proteger_snapshot_split() RETURNS trigger AS $$
BEGIN
    IF (OLD.id_cobranca_asaas IS NOT NULL
        OR OLD.status_pagamento = 'pago'
        OR EXISTS (SELECT 1 FROM asaas_splits s WHERE s.reserva_id=OLD.id AND s.asaas_payment_id IS NOT NULL))
       AND ROW(NEW.split_proprietario_centavos,NEW.split_modalidade,NEW.split_wallet_id_hash,
               NEW.split_versao,NEW.split_preparado_em,NEW.split_origem)
           IS DISTINCT FROM
           ROW(OLD.split_proprietario_centavos,OLD.split_modalidade,OLD.split_wallet_id_hash,
               OLD.split_versao,OLD.split_preparado_em,OLD.split_origem) THEN
        RAISE EXCEPTION 'Snapshot de split imutavel';
    END IF;
    RETURN NEW;
END
$$ LANGUAGE plpgsql;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'public.reservas'::regclass
          AND tgname = 'trg_proteger_snapshot_split'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER trg_proteger_snapshot_split
        BEFORE UPDATE ON reservas FOR EACH ROW
        EXECUTE FUNCTION proteger_snapshot_split();
    END IF;
END
$$;

-- --------------------------------------------------------------------------
-- 7. Refatoracao financeira PIX, reembolsos e repasses
--    Adaptacao segura da migration que no projeto e bloqueada para dev.
-- --------------------------------------------------------------------------
ALTER TABLE configuracoes_financeiras
    ADD COLUMN IF NOT EXISTS taxa_operacao_pix_reserva_centavos INTEGER NOT NULL DEFAULT 200;
ALTER TABLE configuracoes_financeiras DROP CONSTRAINT IF EXISTS chk_config_taxa_operacao_pix;
ALTER TABLE configuracoes_financeiras ADD CONSTRAINT chk_config_taxa_operacao_pix
    CHECK (taxa_operacao_pix_reserva_centavos >= 0) NOT VALID;
ALTER TABLE configuracoes_financeiras VALIDATE CONSTRAINT chk_config_taxa_operacao_pix;

WITH nova AS (
    INSERT INTO configuracoes_financeiras (
        taxa_plataforma_percentual_bps,taxa_plataforma_fixa_centavos,
        taxa_operacao_pix_reserva_centavos,precificacao_ativa,versao
    )
    SELECT 0,0,200,TRUE,COALESCE(MAX(versao),0)+1
    FROM configuracoes_financeiras
    HAVING NOT EXISTS (SELECT 1 FROM configuracoes_financeiras WHERE vigencia_fim IS NULL)
    RETURNING id
)
INSERT INTO taxas_meios_pagamento (
    configuracao_financeira_id,forma_pagamento,quantidade_parcelas,
    percentual_gateway_bps,taxa_fixa_gateway_centavos,margem_seguranca_bps,ativo
)
SELECT id,'PIX',1,0,0,0,TRUE FROM nova;

ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkin_hora_inicial TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkin_hora_final TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkout_hora_inicial TIME;
ALTER TABLE chacaras ADD COLUMN IF NOT EXISTS checkout_hora_final TIME;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_checkin_horas;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_checkin_horas
    CHECK (checkin_hora_inicial IS NULL OR checkin_hora_final IS NULL OR checkin_hora_inicial < checkin_hora_final) NOT VALID;
ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_checkin_horas;
ALTER TABLE chacaras DROP CONSTRAINT IF EXISTS chk_chacaras_checkout_horas;
ALTER TABLE chacaras ADD CONSTRAINT chk_chacaras_checkout_horas
    CHECK (checkout_hora_inicial IS NULL OR checkout_hora_final IS NULL OR checkout_hora_inicial < checkout_hora_final) NOT VALID;
ALTER TABLE chacaras VALIDATE CONSTRAINT chk_chacaras_checkout_horas;

ALTER TABLE reservas ADD COLUMN IF NOT EXISTS taxa_operacao_pix_snapshot_centavos INTEGER;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_hora_inicial_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_hora_final_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkout_hora_inicial_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkout_hora_final_snapshot TIME;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS checkin_inicio_em TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS cancelamento_permitido_ate TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS repasse_liberavel_em TIMESTAMPTZ;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS status_repasse VARCHAR(40);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS aceite_reserva_menos_24h BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE reservas DROP CONSTRAINT IF EXISTS chk_reservas_taxa_operacao_pix;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_taxa_operacao_pix
    CHECK (taxa_operacao_pix_snapshot_centavos IS NULL OR taxa_operacao_pix_snapshot_centavos >= 0) NOT VALID;
ALTER TABLE reservas VALIDATE CONSTRAINT chk_reservas_taxa_operacao_pix;

CREATE TABLE IF NOT EXISTS reembolsos_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    pagamento_id BIGINT REFERENCES pagamentos(id),
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id),
    valor_pago_centavos INTEGER NOT NULL CHECK (valor_pago_centavos > 0),
    valor_reserva_centavos INTEGER NOT NULL CHECK (valor_reserva_centavos > 0),
    taxa_operacao_centavos INTEGER NOT NULL CHECK (taxa_operacao_centavos >= 0),
    valor_reembolso_centavos INTEGER NOT NULL CHECK (valor_reembolso_centavos > 0),
    tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('CANCELAMENTO_USUARIO','CANCELAMENTO_ADMIN','EXCECAO_ADMIN')),
    status_local VARCHAR(30) NOT NULL DEFAULT 'solicitado' CHECK (status_local IN ('solicitado','processando','concluido','falhou','conciliacao_manual','cancelado')),
    status_asaas VARCHAR(80),
    asaas_refund_id VARCHAR(120),
    asaas_payment_id VARCHAR(120),
    motivo VARCHAR(500),
    origem VARCHAR(30) NOT NULL,
    solicitado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processando_em TIMESTAMPTZ,
    concluido_em TIMESTAMPTZ,
    falhou_em TIMESTAMPTZ,
    ultima_sincronizacao_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_reembolso_reserva_tipo_valor UNIQUE (reserva_id,tipo,valor_reembolso_centavos)
);
CREATE INDEX IF NOT EXISTS idx_reembolsos_status
    ON reembolsos_reservas (status_local,solicitado_em);
CREATE TABLE IF NOT EXISTS historico_reembolsos_reservas (
    id BIGSERIAL PRIMARY KEY,
    reembolso_id BIGINT NOT NULL REFERENCES reembolsos_reservas(id),
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(30) NOT NULL,
    evento VARCHAR(80) NOT NULL,
    motivo VARCHAR(500),
    id_externo_mascarado VARCHAR(80),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS repasses_reservas (
    id BIGSERIAL PRIMARY KEY,
    reserva_id BIGINT NOT NULL UNIQUE REFERENCES reservas(id),
    pagamento_id BIGINT REFERENCES pagamentos(id),
    proprietario_id BIGINT NOT NULL REFERENCES proprietarios(id),
    asaas_subconta_id BIGINT,
    ambiente VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    wallet_id VARCHAR(160),
    wallet_hash CHAR(64),
    valor_reserva_centavos INTEGER NOT NULL CHECK (valor_reserva_centavos > 0),
    valor_repasse_centavos INTEGER NOT NULL CHECK (valor_repasse_centavos = valor_reserva_centavos),
    repasse_liberavel_em TIMESTAMPTZ NOT NULL,
    status_local VARCHAR(30) NOT NULL DEFAULT 'aguardando_pagamento' CHECK (status_local IN ('aguardando_pagamento','aguardando_liberacao','liberado_para_repasse','processando','concluido','falhou','conciliacao_manual','cancelado')),
    status_asaas VARCHAR(80),
    asaas_transfer_id VARCHAR(120),
    external_reference VARCHAR(120) NOT NULL UNIQUE,
    tentativa_count INTEGER NOT NULL DEFAULT 0 CHECK (tentativa_count >= 0),
    erro_codigo VARCHAR(80),
    erro_motivo VARCHAR(500),
    conciliacao_manual BOOLEAN NOT NULL DEFAULT FALSE,
    solicitado_em TIMESTAMPTZ,
    processando_em TIMESTAMPTZ,
    concluido_em TIMESTAMPTZ,
    ultima_sincronizacao_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_repasses_fila
    ON repasses_reservas (status_local,repasse_liberavel_em);
CREATE UNIQUE INDEX IF NOT EXISTS uq_repasses_transfer_id
    ON repasses_reservas (asaas_transfer_id) WHERE asaas_transfer_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS historico_repasses_reservas (
    id BIGSERIAL PRIMARY KEY,
    repasse_id BIGINT NOT NULL REFERENCES repasses_reservas(id),
    reserva_id BIGINT NOT NULL REFERENCES reservas(id),
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    origem VARCHAR(30) NOT NULL,
    evento VARCHAR(80) NOT NULL,
    motivo VARCHAR(500),
    asaas_transfer_id VARCHAR(120),
    metadados_sanitizados JSONB NOT NULL DEFAULT '{}'::jsonb,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------------------------
-- 8. Campos Asaas usados pelo codigo atual
--    Nao executa asaas_sandbox_homologation_migration.sql. Os quatro campos
--    foram validados diretamente contra Reserva::atualizarCobrancaAsaas().
-- --------------------------------------------------------------------------
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS pix_qr_code_base64 TEXT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS pix_copia_cola TEXT;
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS asaas_status VARCHAR(80);
ALTER TABLE reservas ADD COLUMN IF NOT EXISTS asaas_vencimento DATE;

-- Pos-condicoes: se alguma falhar, o COMMIT nao ocorre.
DO $$
DECLARE
    faltantes text;
BEGIN
    SELECT string_agg(item, ', ' ORDER BY item) INTO faltantes
    FROM (
        SELECT v.item
        FROM (VALUES
            ('reservas.valor_total_cliente_centavos'),
            ('reservas.valor_hospedagem_centavos'),
            ('reservas.taxa_operacao_pix_snapshot_centavos'),
            ('reservas.pix_qr_code_base64'),
            ('configuracoes_financeiras'),
            ('historico_status_reservas'),
            ('asaas_webhook_eventos'),
            ('proprietario_dados_financeiros'),
            ('asaas_subcontas'),
            ('asaas_splits'),
            ('reembolsos_reservas'),
            ('repasses_reservas')
        ) AS v(item)
        WHERE CASE
            WHEN position('.' in v.item) > 0 THEN NOT EXISTS (
                SELECT 1 FROM information_schema.columns c
                WHERE c.table_schema='public'
                  AND c.table_name=split_part(v.item,'.',1)
                  AND c.column_name=split_part(v.item,'.',2)
            )
            ELSE to_regclass('public.' || v.item) IS NULL
        END
    ) ausentes;
    IF faltantes IS NOT NULL THEN
        RAISE EXCEPTION 'Atualizacao incompleta; objetos ausentes: %', faltantes;
    END IF;
    IF EXISTS (SELECT 1 FROM reservas WHERE valor_total_cliente_centavos IS NULL) THEN
        RAISE EXCEPTION 'Backfill incompleto: reserva sem valor_total_cliente_centavos.';
    END IF;
END
$$;

COMMIT;

-- Resultado esperado apos sucesso.
SELECT
    current_database() AS banco,
    COUNT(*) AS reservas,
    COUNT(*) FILTER (WHERE valor_total_cliente_centavos IS NOT NULL) AS reservas_precificadas
FROM reservas;
