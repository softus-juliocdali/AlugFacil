-- ============================================================================
-- Alug Fácil - Banco PostgreSQL 14+
-- Execute este arquivo conectado ao banco "alugfacil".
-- ATENÇÃO: as tabelas existentes são removidas para uma instalação limpa.
-- ============================================================================

BEGIN;

DROP TABLE IF EXISTS avaliacoes CASCADE;
DROP TABLE IF EXISTS favoritos_chacaras CASCADE;
DROP TABLE IF EXISTS pagamentos CASCADE;
DROP TABLE IF EXISTS reservas CASCADE;
DROP TABLE IF EXISTS disponibilidades CASCADE;
DROP TABLE IF EXISTS chacara_fotos CASCADE;
DROP TABLE IF EXISTS chacaras CASCADE;
DROP TABLE IF EXISTS proprietarios CASCADE;
DROP TABLE IF EXISTS password_resets CASCADE;
DROP TABLE IF EXISTS usuarios CASCADE;
DROP FUNCTION IF EXISTS atualizar_data_atualizacao_alugfacil() CASCADE;

CREATE TABLE usuarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(30),
    email VARCHAR(180) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    tipo_usuario VARCHAR(20) NOT NULL DEFAULT 'cliente',
    status VARCHAR(15) NOT NULL DEFAULT 'ativo',
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_usuarios_nome CHECK (LENGTH(TRIM(nome)) >= 2),
    CONSTRAINT chk_usuarios_email CHECK (POSITION('@' IN email) > 1),
    CONSTRAINT chk_usuarios_tipo
        CHECK (tipo_usuario IN ('cliente', 'proprietario', 'admin')),
    CONSTRAINT chk_usuarios_status
        CHECK (status IN ('ativo', 'bloqueado'))
);

CREATE TABLE proprietarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL UNIQUE,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(30),
    email VARCHAR(180) NOT NULL UNIQUE,
    cpf VARCHAR(11) UNIQUE,
    status VARCHAR(15) NOT NULL DEFAULT 'pendente',
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_proprietarios_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_proprietarios_cpf
        CHECK (cpf IS NULL OR cpf ~ '^[0-9]{11}$'),
    CONSTRAINT chk_proprietarios_status
        CHECK (status IN ('ativo', 'bloqueado', 'pendente'))
);

CREATE TABLE password_resets (
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

CREATE TABLE chacaras (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proprietario_id INTEGER NOT NULL,
    nome VARCHAR(180) NOT NULL,
    descricao TEXT,
    tipo_imovel VARCHAR(20) NOT NULL DEFAULT 'chacara',
    valor_diaria NUMERIC(10,2) NOT NULL,
    cidade VARCHAR(100) NOT NULL,
    estado CHAR(2),
    regiao VARCHAR(100),
    endereco VARCHAR(255) NOT NULL,
    latitude NUMERIC(10,7),
    longitude NUMERIC(10,7),
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    foto_principal VARCHAR(255),
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chacaras_proprietario
        FOREIGN KEY (proprietario_id) REFERENCES proprietarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_chacaras_nome CHECK (LENGTH(TRIM(nome)) >= 3),
    CONSTRAINT chk_chacaras_tipo_imovel
        CHECK (tipo_imovel IN ('chacara', 'sitio', 'area_lazer')),
    CONSTRAINT chk_chacaras_valor_diaria CHECK (valor_diaria > 0),
    CONSTRAINT chk_chacaras_estado
        CHECK (estado IS NULL OR estado IN (
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
            'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
            'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
        )),
    CONSTRAINT chk_chacaras_latitude
        CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_chacaras_longitude
        CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_chacaras_status
        CHECK (status IN ('disponivel', 'indisponivel', 'pendente', 'bloqueada'))
);

CREATE TABLE chacara_fotos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chacara_id INTEGER NOT NULL,
    caminho_foto VARCHAR(255) NOT NULL,
    principal VARCHAR(3) NOT NULL DEFAULT 'nao',
    ordem INTEGER NOT NULL DEFAULT 0,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chacara_fotos_chacara
        FOREIGN KEY (chacara_id) REFERENCES chacaras(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_chacara_fotos_principal CHECK (principal IN ('sim', 'nao')),
    CONSTRAINT chk_chacara_fotos_ordem CHECK (ordem >= 0),
    CONSTRAINT uq_chacara_foto_caminho UNIQUE (chacara_id, caminho_foto)
);

CREATE TABLE disponibilidades (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chacara_id INTEGER NOT NULL,
    data DATE NOT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'disponivel',
    observacao TEXT,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disponibilidades_chacara
        FOREIGN KEY (chacara_id) REFERENCES chacaras(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_disponibilidades_status
        CHECK (status IN ('disponivel', 'reservado', 'bloqueado')),
    CONSTRAINT uq_disponibilidade_chacara_data UNIQUE (chacara_id, data)
);

CREATE TABLE reservas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    proprietario_id INTEGER NOT NULL,
    chacara_id INTEGER NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    quantidade_diarias INTEGER NOT NULL,
    valor_diaria NUMERIC(10,2) NOT NULL,
    valor_total NUMERIC(10,2) NOT NULL,
    status_reserva VARCHAR(30) NOT NULL DEFAULT 'solicitada',
    status_pagamento VARCHAR(15) NOT NULL DEFAULT 'pendente',
    id_cobranca_asaas VARCHAR(100),
    link_pagamento_asaas VARCHAR(500),
    data_reserva TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reservas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservas_proprietario
        FOREIGN KEY (proprietario_id) REFERENCES proprietarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservas_chacara
        FOREIGN KEY (chacara_id) REFERENCES chacaras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_reservas_periodo CHECK (data_fim > data_inicio),
    CONSTRAINT chk_reservas_quantidade_diarias CHECK (
        quantidade_diarias > 0
        AND quantidade_diarias = (data_fim - data_inicio)
    ),
    CONSTRAINT chk_reservas_valor_diaria CHECK (valor_diaria > 0),
    CONSTRAINT chk_reservas_valor_total CHECK (
        valor_total >= 0
        AND valor_total = ROUND(valor_diaria * quantidade_diarias, 2)
    ),
    CONSTRAINT chk_reservas_status CHECK (
        status_reserva IN (
            'solicitada', 'aguardando_pagamento', 'pagamento_confirmado',
            'confirmada', 'cancelada', 'finalizada'
        )
    ),
    CONSTRAINT chk_reservas_status_pagamento
        CHECK (status_pagamento IN ('pendente', 'pago', 'cancelado', 'estornado')),
    CONSTRAINT uq_reservas_cobranca_asaas UNIQUE (id_cobranca_asaas)
);

CREATE TABLE pagamentos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reserva_id INTEGER NOT NULL,
    usuario_id INTEGER NOT NULL,
    valor NUMERIC(10,2) NOT NULL,
    forma_pagamento VARCHAR(30) NOT NULL,
    status_pagamento VARCHAR(15) NOT NULL DEFAULT 'pendente',
    id_transacao_asaas VARCHAR(100),
    data_pagamento TIMESTAMP,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagamentos_reserva
        FOREIGN KEY (reserva_id) REFERENCES reservas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_pagamentos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_pagamentos_valor CHECK (valor > 0),
    CONSTRAINT chk_pagamentos_forma
        CHECK (forma_pagamento IN ('pix', 'cartao_credito', 'boleto', 'transferencia')),
    CONSTRAINT chk_pagamentos_status
        CHECK (status_pagamento IN ('pendente', 'pago', 'cancelado', 'estornado')),
    CONSTRAINT chk_pagamentos_data
        CHECK (status_pagamento <> 'pago' OR data_pagamento IS NOT NULL),
    CONSTRAINT uq_pagamentos_transacao_asaas UNIQUE (id_transacao_asaas)
);

CREATE TABLE avaliacoes (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    chacara_id INTEGER NOT NULL,
    reserva_id INTEGER NOT NULL UNIQUE,
    nota SMALLINT NOT NULL,
    comentario TEXT,
    status VARCHAR(10) NOT NULL DEFAULT 'ativo',
    data_avaliacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_avaliacoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_avaliacoes_chacara
        FOREIGN KEY (chacara_id) REFERENCES chacaras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_avaliacoes_reserva
        FOREIGN KEY (reserva_id) REFERENCES reservas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_avaliacoes_nota CHECK (nota BETWEEN 1 AND 5),
    CONSTRAINT chk_avaliacoes_status CHECK (status IN ('ativo', 'oculto'))
);

-- Índices de consulta. As constraints UNIQUE já indexam os e-mails.
CREATE TABLE favoritos_chacaras (
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

CREATE INDEX idx_usuarios_email_lower ON usuarios (LOWER(email));
CREATE INDEX idx_usuarios_tipo_status ON usuarios (tipo_usuario, status);
CREATE INDEX idx_proprietarios_status ON proprietarios (status);
CREATE INDEX idx_password_resets_usuario ON password_resets (usuario_id);
CREATE INDEX idx_password_resets_expiracao ON password_resets (expira_em);
CREATE INDEX idx_chacaras_cidade ON chacaras (cidade);
CREATE INDEX idx_chacaras_regiao ON chacaras (regiao);
CREATE INDEX idx_chacaras_tipo_imovel ON chacaras (tipo_imovel);
CREATE INDEX idx_chacaras_status ON chacaras (status);
CREATE INDEX idx_chacaras_proprietario ON chacaras (proprietario_id);
CREATE INDEX idx_chacaras_busca_localizacao ON chacaras (cidade, regiao, status);
CREATE INDEX idx_favoritos_chacaras_chacara ON favoritos_chacaras (chacara_id);
CREATE INDEX idx_chacara_fotos_chacara_ordem ON chacara_fotos (chacara_id, ordem);
CREATE UNIQUE INDEX uq_chacara_foto_principal
    ON chacara_fotos (chacara_id) WHERE principal = 'sim';
CREATE INDEX idx_disponibilidades_data_status
    ON disponibilidades (data, status);
CREATE INDEX idx_disponibilidades_chacara_data
    ON disponibilidades (chacara_id, data);
CREATE INDEX idx_reservas_data_inicio_fim
    ON reservas (data_inicio, data_fim);
CREATE INDEX idx_reservas_chacara_datas
    ON reservas (chacara_id, data_inicio, data_fim);
CREATE INDEX idx_reservas_usuario
    ON reservas (usuario_id, data_reserva DESC);
CREATE INDEX idx_reservas_proprietario
    ON reservas (proprietario_id, data_reserva DESC);
CREATE INDEX idx_reservas_status
    ON reservas (status_reserva, status_pagamento);
CREATE INDEX idx_pagamentos_reserva ON pagamentos (reserva_id);
CREATE INDEX idx_pagamentos_usuario ON pagamentos (usuario_id);
CREATE INDEX idx_avaliacoes_chacara_status
    ON avaliacoes (chacara_id, status);

-- Atualiza automaticamente os campos data_atualizacao.
CREATE FUNCTION atualizar_data_atualizacao_alugfacil()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_usuarios_data_atualizacao
BEFORE UPDATE ON usuarios
FOR EACH ROW EXECUTE FUNCTION atualizar_data_atualizacao_alugfacil();

CREATE TRIGGER trg_proprietarios_data_atualizacao
BEFORE UPDATE ON proprietarios
FOR EACH ROW EXECUTE FUNCTION atualizar_data_atualizacao_alugfacil();

CREATE TRIGGER trg_chacaras_data_atualizacao
BEFORE UPDATE ON chacaras
FOR EACH ROW EXECUTE FUNCTION atualizar_data_atualizacao_alugfacil();

CREATE TRIGGER trg_reservas_data_atualizacao
BEFORE UPDATE ON reservas
FOR EACH ROW EXECUTE FUNCTION atualizar_data_atualizacao_alugfacil();

-- ============================================================================
-- Dados iniciais
-- Todas as contas abaixo usam a senha 123456.
-- Hash criado com password_hash('123456', PASSWORD_DEFAULT) no PHP 8.5.
-- ============================================================================

INSERT INTO usuarios
    (nome, telefone, email, senha_hash, tipo_usuario, status)
VALUES
    ('Administrador', '(11) 99999-0000', 'admin@alugfacil.com.br',
     '$2y$12$1/Eq95Csr6NXQnG1XWTPpufwKga1YnyE5zHSGyrX7TarYl.3V9LSe',
     'admin', 'ativo'),
    ('Mariana Souza', '(11) 98888-1001', 'mariana@exemplo.com',
     '$2y$12$1/Eq95Csr6NXQnG1XWTPpufwKga1YnyE5zHSGyrX7TarYl.3V9LSe',
     'cliente', 'ativo'),
    ('Rafael Oliveira', '(15) 97777-2002', 'rafael@exemplo.com',
     '$2y$12$1/Eq95Csr6NXQnG1XWTPpufwKga1YnyE5zHSGyrX7TarYl.3V9LSe',
     'cliente', 'ativo'),
    ('Carlos Ribeiro', '(11) 96666-3003', 'carlos@exemplo.com',
     '$2y$12$1/Eq95Csr6NXQnG1XWTPpufwKga1YnyE5zHSGyrX7TarYl.3V9LSe',
     'proprietario', 'ativo'),
    ('Fernanda Almeida', '(15) 95555-4004', 'fernanda@exemplo.com',
     '$2y$12$1/Eq95Csr6NXQnG1XWTPpufwKga1YnyE5zHSGyrX7TarYl.3V9LSe',
     'proprietario', 'ativo');

INSERT INTO proprietarios (usuario_id, nome, telefone, email, cpf, status)
VALUES
    ((SELECT id FROM usuarios WHERE email = 'carlos@exemplo.com'),
     'Carlos Ribeiro', '(11) 96666-3003', 'carlos@exemplo.com', '52998224725', 'ativo'),
    ((SELECT id FROM usuarios WHERE email = 'fernanda@exemplo.com'),
     'Fernanda Almeida', '(15) 95555-4004', 'fernanda@exemplo.com', '39053344705', 'ativo');

INSERT INTO chacaras
    (proprietario_id, nome, descricao, tipo_imovel, valor_diaria, cidade, regiao,
     endereco, latitude, longitude, status, foto_principal)
VALUES
    ((SELECT id FROM proprietarios WHERE email = 'carlos@exemplo.com'),
     'Chacara Paraiso Verde',
     'Chacara ampla com piscina, churrasqueira e area verde para familias.',
     'chacara', 850.00, 'Ibiuna', 'Interior de Sao Paulo',
     'Estrada Municipal das Palmeiras, 1200',
     -23.6565000, -47.2231000, 'disponivel',
     'uploads/chacaras/chacara-paraiso-verde-01.jpg'),
    ((SELECT id FROM proprietarios WHERE email = 'carlos@exemplo.com'),
     'Recanto do Sossego',
     'Espaco reservado, cercado pela natureza e proximo ao centro.',
     'area_lazer', 650.00, 'Mairinque', 'Regiao de Sorocaba',
     'Rua das Acacias, 340',
     -23.5458000, -47.1850000, 'disponivel',
     'uploads/chacaras/recanto-sossego-01.jpg'),
    ((SELECT id FROM proprietarios WHERE email = 'fernanda@exemplo.com'),
     'Sitio Bela Vista',
     'Sitio completo com piscina, salao de jogos e vista para as montanhas.',
     'sitio', 1100.00, 'Sao Roque', 'Rota do Vinho',
     'Estrada do Vinho, km 8',
     -23.5329000, -47.1356000, 'disponivel',
     'uploads/chacaras/sitio-bela-vista-01.jpg');

INSERT INTO chacara_fotos (chacara_id, caminho_foto, principal, ordem)
VALUES
    ((SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     'uploads/chacaras/chacara-paraiso-verde-01.jpg', 'sim', 1),
    ((SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     'uploads/chacaras/chacara-paraiso-verde-02.jpg', 'nao', 2),
    ((SELECT id FROM chacaras WHERE nome = 'Recanto do Sossego'),
     'uploads/chacaras/recanto-sossego-01.jpg', 'sim', 1),
    ((SELECT id FROM chacaras WHERE nome = 'Recanto do Sossego'),
     'uploads/chacaras/recanto-sossego-02.jpg', 'nao', 2),
    ((SELECT id FROM chacaras WHERE nome = 'Sitio Bela Vista'),
     'uploads/chacaras/sitio-bela-vista-01.jpg', 'sim', 1),
    ((SELECT id FROM chacaras WHERE nome = 'Sitio Bela Vista'),
     'uploads/chacaras/sitio-bela-vista-02.jpg', 'nao', 2);

INSERT INTO disponibilidades (chacara_id, data, status, observacao)
VALUES
    ((SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     DATE '2026-07-18', 'reservado', 'Reserva confirmada.'),
    ((SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     DATE '2026-07-19', 'reservado', 'Reserva confirmada.'),
    ((SELECT id FROM chacaras WHERE nome = 'Recanto do Sossego'),
     DATE '2026-07-25', 'reservado', 'Aguardando pagamento.'),
    ((SELECT id FROM chacaras WHERE nome = 'Recanto do Sossego'),
     DATE '2026-07-26', 'reservado', 'Aguardando pagamento.'),
    ((SELECT id FROM chacaras WHERE nome = 'Sitio Bela Vista'),
     DATE '2026-08-08', 'bloqueado', 'Manutenção preventiva.');

INSERT INTO reservas
    (usuario_id, proprietario_id, chacara_id, data_inicio, data_fim,
     quantidade_diarias, valor_diaria, valor_total, status_reserva,
     status_pagamento, id_cobranca_asaas, link_pagamento_asaas, data_reserva)
VALUES
    ((SELECT id FROM usuarios WHERE email = 'mariana@exemplo.com'),
     (SELECT id FROM proprietarios WHERE email = 'carlos@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     DATE '2026-07-18', DATE '2026-07-20', 2, 850.00, 1700.00,
     'confirmada', 'pago', 'pay_demo_0001',
     'https://sandbox.asaas.com/i/pay_demo_0001',
     TIMESTAMP '2026-06-15 10:30:00'),
    ((SELECT id FROM usuarios WHERE email = 'rafael@exemplo.com'),
     (SELECT id FROM proprietarios WHERE email = 'carlos@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Recanto do Sossego'),
     DATE '2026-07-25', DATE '2026-07-27', 2, 650.00, 1300.00,
     'aguardando_pagamento', 'pendente', 'pay_demo_0002',
     'https://sandbox.asaas.com/i/pay_demo_0002',
     TIMESTAMP '2026-06-20 14:15:00'),
    ((SELECT id FROM usuarios WHERE email = 'mariana@exemplo.com'),
     (SELECT id FROM proprietarios WHERE email = 'fernanda@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Sitio Bela Vista'),
     DATE '2026-05-02', DATE '2026-05-04', 2, 1100.00, 2200.00,
     'finalizada', 'pago', 'pay_demo_0003',
     'https://sandbox.asaas.com/i/pay_demo_0003',
     TIMESTAMP '2026-04-10 09:00:00'),
    ((SELECT id FROM usuarios WHERE email = 'rafael@exemplo.com'),
     (SELECT id FROM proprietarios WHERE email = 'carlos@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     DATE '2026-04-11', DATE '2026-04-13', 2, 850.00, 1700.00,
     'finalizada', 'pago', 'pay_demo_0004',
     'https://sandbox.asaas.com/i/pay_demo_0004',
     TIMESTAMP '2026-03-20 16:45:00');

INSERT INTO pagamentos
    (reserva_id, usuario_id, valor, forma_pagamento, status_pagamento,
     id_transacao_asaas, data_pagamento)
VALUES
    ((SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0001'),
     (SELECT id FROM usuarios WHERE email = 'mariana@exemplo.com'),
     1700.00, 'pix', 'pago', 'txn_demo_0001',
     TIMESTAMP '2026-06-15 10:37:00'),
    ((SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0002'),
     (SELECT id FROM usuarios WHERE email = 'rafael@exemplo.com'),
     1300.00, 'boleto', 'pendente', 'txn_demo_0002', NULL),
    ((SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0003'),
     (SELECT id FROM usuarios WHERE email = 'mariana@exemplo.com'),
     2200.00, 'cartao_credito', 'pago', 'txn_demo_0003',
     TIMESTAMP '2026-04-10 09:04:00'),
    ((SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0004'),
     (SELECT id FROM usuarios WHERE email = 'rafael@exemplo.com'),
     1700.00, 'pix', 'pago', 'txn_demo_0004',
     TIMESTAMP '2026-03-20 16:48:00');

INSERT INTO avaliacoes
    (usuario_id, chacara_id, reserva_id, nota, comentario, status, data_avaliacao)
VALUES
    ((SELECT id FROM usuarios WHERE email = 'mariana@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Sitio Bela Vista'),
     (SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0003'),
     5, 'Lugar maravilhoso, muito limpo e com uma vista incrível.',
     'ativo', TIMESTAMP '2026-05-05 18:20:00'),
    ((SELECT id FROM usuarios WHERE email = 'rafael@exemplo.com'),
     (SELECT id FROM chacaras WHERE nome = 'Chacara Paraiso Verde'),
     (SELECT id FROM reservas WHERE id_cobranca_asaas = 'pay_demo_0004'),
     5, 'Ótima estrutura para a família e atendimento excelente.',
     'ativo', TIMESTAMP '2026-04-14 11:10:00');

COMMIT;

-- Conferência:
-- SELECT id, nome, email, tipo_usuario, status FROM usuarios ORDER BY id;
-- SELECT id, nome, cidade, valor_diaria, status FROM chacaras ORDER BY id;
-- SELECT id, chacara_id, data_inicio, data_fim, valor_total FROM reservas;
