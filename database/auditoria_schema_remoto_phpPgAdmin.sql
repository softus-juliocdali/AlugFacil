-- Alug Facil - inventario SOMENTE LEITURA do schema remoto.
-- Compativel com PostgreSQL/phpPgAdmin. Nao cria, altera ou remove objetos/dados.
-- Execute conectado ao banco de producao e salve os quatro resultados.

SELECT
    current_database() AS banco,
    current_user AS usuario_conectado,
    current_schema() AS schema_atual,
    current_setting('server_version') AS versao_postgresql,
    pg_size_pretty(pg_database_size(current_database())) AS tamanho_banco;

-- Resultado 1: tabelas esperadas que estao ausentes.
WITH esperadas(tabela) AS (
    VALUES
        ('password_resets'),
        ('favoritos_chacaras'),
        ('historico_status_proprietarios'),
        ('historico_status_chacaras'),
        ('historico_status_reservas'),
        ('asaas_webhook_eventos'),
        ('configuracoes_financeiras'),
        ('taxas_meios_pagamento'),
        ('historico_configuracoes_financeiras'),
        ('cotacoes_reserva'),
        ('proprietario_dados_financeiros'),
        ('aceites_financeiros_proprietarios'),
        ('asaas_subcontas'),
        ('historico_asaas_subcontas'),
        ('auditoria_dados_financeiros'),
        ('asaas_splits'),
        ('historico_asaas_splits'),
        ('reembolsos_reservas'),
        ('historico_reembolsos_reservas'),
        ('repasses_reservas'),
        ('historico_repasses_reservas')
)
SELECT 'TABELA_AUSENTE' AS tipo, tabela AS objeto
FROM esperadas
WHERE to_regclass('public.' || tabela) IS NULL
ORDER BY tabela;

-- Resultado 2: colunas adicionadas pelas migrations que estao ausentes.
WITH esperadas(tabela, coluna) AS (
    VALUES
        ('proprietarios','cpf'),
        ('proprietarios','motivo_status'),
        ('proprietarios','status_decidido_em'),
        ('proprietarios','status_decidido_por'),
        ('chacaras','tipo_imovel'),
        ('chacaras','status_aprovacao'),
        ('chacaras','status_operacional'),
        ('chacaras','motivo_status'),
        ('chacaras','status_decidido_em'),
        ('chacaras','status_decidido_por'),
        ('chacaras','checkin_hora_inicial'),
        ('chacaras','checkin_hora_final'),
        ('chacaras','checkout_hora_inicial'),
        ('chacaras','checkout_hora_final'),
        ('reservas','expira_em'),
        ('reservas','cancelada_em'),
        ('reservas','cancelada_por_tipo'),
        ('reservas','cancelada_por_id'),
        ('reservas','motivo_cancelamento'),
        ('reservas','cancelamento_solicitado_em'),
        ('reservas','inicio_real_em'),
        ('reservas','finalizada_em'),
        ('reservas','ultima_transicao_em'),
        ('reservas','versao_status'),
        ('reservas','divergencia_pagamento'),
        ('reservas','valor_diaria_liquido_proprietario_centavos'),
        ('reservas','valor_hospedagem_centavos'),
        ('reservas','valor_liquido_proprietario_centavos'),
        ('reservas','taxa_plataforma_centavos'),
        ('reservas','taxa_gateway_estimada_centavos'),
        ('reservas','valor_total_cliente_centavos'),
        ('reservas','plataforma_percentual_bps'),
        ('reservas','plataforma_fixa_centavos'),
        ('reservas','gateway_percentual_bps'),
        ('reservas','gateway_fixa_centavos'),
        ('reservas','margem_seguranca_bps'),
        ('reservas','forma_pagamento'),
        ('reservas','quantidade_parcelas'),
        ('reservas','versao_precificacao'),
        ('reservas','origem_precificacao'),
        ('reservas','precificacao_detalhes'),
        ('reservas','precificado_em'),
        ('reservas','split_proprietario_centavos'),
        ('reservas','split_modalidade'),
        ('reservas','split_wallet_id_hash'),
        ('reservas','split_versao'),
        ('reservas','split_preparado_em'),
        ('reservas','split_origem'),
        ('reservas','split_status_resumo'),
        ('reservas','taxa_operacao_pix_snapshot_centavos'),
        ('reservas','checkin_hora_inicial_snapshot'),
        ('reservas','checkin_hora_final_snapshot'),
        ('reservas','checkout_hora_inicial_snapshot'),
        ('reservas','checkout_hora_final_snapshot'),
        ('reservas','checkin_inicio_em'),
        ('reservas','cancelamento_permitido_ate'),
        ('reservas','repasse_liberavel_em'),
        ('reservas','status_repasse'),
        ('reservas','aceite_reserva_menos_24h'),
        ('reservas','pix_qr_code_base64'),
        ('reservas','pix_copia_cola'),
        ('reservas','asaas_status'),
        ('reservas','asaas_vencimento'),
        ('configuracoes_financeiras','taxa_operacao_pix_reserva_centavos')
)
SELECT 'COLUNA_AUSENTE' AS tipo, e.tabela || '.' || e.coluna AS objeto
FROM esperadas e
LEFT JOIN information_schema.columns c
  ON c.table_schema = 'public'
 AND c.table_name = e.tabela
 AND c.column_name = e.coluna
WHERE c.column_name IS NULL
ORDER BY e.tabela, e.coluna;

-- Resultado 3: objetos de integridade/desempenho esperados que estao ausentes.
WITH esperados(tipo, nome) AS (
    VALUES
        ('index','uq_proprietarios_cpf'),
        ('index','idx_favoritos_chacaras_chacara'),
        ('index','idx_chacaras_tipo_imovel'),
        ('index','idx_chacaras_publicacao'),
        ('index','idx_historico_proprietarios_entidade'),
        ('index','idx_historico_chacaras_entidade'),
        ('index','idx_reservas_expiracao'),
        ('index','idx_reservas_bloqueio_datas'),
        ('index','idx_historico_reserva'),
        ('index','uq_pagamento_ativo_reserva'),
        ('index','idx_asaas_webhook_fila'),
        ('index','idx_asaas_webhook_payment'),
        ('index','idx_asaas_webhook_recebido'),
        ('index','uq_configuracao_financeira_vigente'),
        ('index','idx_cotacoes_reserva_validade'),
        ('index','uq_aceite_financeiro_vigente'),
        ('index','uq_asaas_subconta_proprietario_ambiente'),
        ('index','idx_asaas_subconta_request_hash'),
        ('index','idx_asaas_subconta_status'),
        ('index','idx_historico_asaas_subconta'),
        ('index','uq_asaas_split_reserva_proprietario'),
        ('index','idx_asaas_split_reserva'),
        ('index','idx_asaas_split_pagamento'),
        ('index','idx_asaas_split_proprietario'),
        ('index','idx_asaas_split_status_datas'),
        ('index','uq_historico_split_evento'),
        ('index','idx_historico_split'),
        ('index','idx_reembolsos_status'),
        ('index','idx_repasses_fila'),
        ('index','uq_repasses_transfer_id'),
        ('trigger','trg_proteger_snapshot_financeiro_pago'),
        ('trigger','trg_proteger_snapshot_split')
), presentes AS (
    SELECT 'index'::text AS tipo, indexname AS nome
    FROM pg_indexes WHERE schemaname = 'public'
    UNION ALL
    SELECT 'trigger', tgname
    FROM pg_trigger
    WHERE NOT tgisinternal
      AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace = 'public'::regnamespace)
)
SELECT upper(e.tipo) || '_AUSENTE' AS tipo, e.nome AS objeto
FROM esperados e
LEFT JOIN presentes p ON p.tipo = e.tipo AND p.nome = e.nome
WHERE p.nome IS NULL
ORDER BY e.tipo, e.nome;

-- Resultado 4: tipos/comprimentos divergentes que podem quebrar o codigo.
WITH esperado(tabela, coluna, udt_name, tamanho_minimo) AS (
    VALUES
        ('aceites_financeiros_proprietarios','versao_documento','varchar',100),
        ('chacaras','checkin_hora_inicial','time',NULL),
        ('chacaras','checkin_hora_final','time',NULL),
        ('chacaras','checkout_hora_inicial','time',NULL),
        ('chacaras','checkout_hora_final','time',NULL),
        ('reservas','valor_total_cliente_centavos','int8',NULL),
        ('reservas','valor_liquido_proprietario_centavos','int8',NULL),
        ('reservas','valor_hospedagem_centavos','int8',NULL),
        ('reservas','precificacao_detalhes','jsonb',NULL),
        ('reservas','checkin_inicio_em','timestamptz',NULL),
        ('reservas','cancelamento_permitido_ate','timestamptz',NULL),
        ('reservas','repasse_liberavel_em','timestamptz',NULL)
)
SELECT
    'TIPO_OU_TAMANHO_DIVERGENTE' AS tipo,
    e.tabela || '.' || e.coluna AS objeto,
    c.udt_name AS tipo_atual,
    c.character_maximum_length AS tamanho_atual,
    e.udt_name AS tipo_esperado,
    e.tamanho_minimo
FROM esperado e
JOIN information_schema.columns c
  ON c.table_schema = 'public'
 AND c.table_name = e.tabela
 AND c.column_name = e.coluna
WHERE c.udt_name <> e.udt_name
   OR (e.tamanho_minimo IS NOT NULL AND COALESCE(c.character_maximum_length, 0) < e.tamanho_minimo)
ORDER BY e.tabela, e.coluna;

-- Resultado 5: diagnostico de dados que pode impedir constraints/indices unicos.
SELECT 'reservas' AS entidade, 'total' AS metrica, COUNT(*)::bigint AS quantidade FROM reservas
UNION ALL
SELECT 'reservas', 'valor_total_nulo', COUNT(*) FROM reservas WHERE valor_total IS NULL
UNION ALL
SELECT 'reservas', 'valor_diaria_nulo', COUNT(*) FROM reservas WHERE valor_diaria IS NULL
UNION ALL
SELECT 'pagamentos', 'total', COUNT(*) FROM pagamentos
UNION ALL
SELECT 'pagamentos', 'reservas_com_mais_de_um_pagamento_ativo', COUNT(*)
FROM (
    SELECT reserva_id
    FROM pagamentos
    WHERE status_pagamento IN ('pendente','pago')
    GROUP BY reserva_id
    HAVING COUNT(*) > 1
) duplicados
UNION ALL
SELECT 'proprietarios', 'cpf_duplicado_nao_nulo', COUNT(*)
FROM (
    SELECT cpf
    FROM proprietarios
    WHERE cpf IS NOT NULL
    GROUP BY cpf
    HAVING COUNT(*) > 1
) duplicados;
