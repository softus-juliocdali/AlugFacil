# Runbook de implantação — Módulo de afiliados do Alug Fácil

Preparação: 20/08/2026  
Branch de origem: master  
HEAD de origem: b8cd0023fe323963b22c965529e5e825dfd85e49

Este documento orienta uma implantação controlada. Ele não autoriza execução sem janela aprovada, backup conferido e acesso formal ao ambiente de produção.

**Primeira ação obrigatória: Backup completo do banco de produção.**

## Classificação dos SQLs

1. **asaas_webhook_migration.sql — já existente / não executar integralmente.** A produção já possui integração Asaas. Confirmar a tabela asaas_webhook_eventos, índices e autenticação do endpoint. Se algo estiver ausente, interromper e preparar um delta após comparar o schema real.
2. **mensalidade_anuncio_migration.sql — já existente / não executar integralmente.** A produção já possui mensalidades. Confirmar mensalidades_anuncios e cobrancas_mensalidades.
3. **affiliate_production_migration.sql — obrigatório.** É o delta consolidado e depende das estruturas anteriores.
4. **affiliate_migration.sql, affiliate_attribution_migration.sql e affiliate_commission_migration.sql — referência.** Não executar se o consolidado for utilizado.

## Fase A — Preparação

1. Fazer **backup completo do banco de produção**.
2. Validar que o backup pode ser restaurado.
3. Fazer backup dos arquivos que serão sobrescritos, preservando caminhos e permissões.
4. Confirmar a janela controlada e os responsáveis por aplicação, banco, validação e rollback.
5. Confirmar que o banco configurado é o banco de produção correto e não alugfacil_dev.
6. Confirmar PHP compatível, pdo_pgsql, PostgreSQL e document root apontando para public/.
7. Conferir o .env existente sem substituí-lo e sem copiar valores para logs.
8. Adicionar, se ausente:

~~~dotenv
AFFILIATE_COOKIE_SECRET=<GERAR_SEGREDO_ALEATORIO_FORTE>
~~~

O valor deve ter no mínimo 32 caracteres aleatórios, permanecer estável, não ser reutilizado e nunca ser versionado.

9. Conferir sem revelar valores: APP_ENV, APP_URL HTTPS, APP_TIMEZONE, DB_*, ASAAS_ENVIRONMENT, ASAAS_BASE_URL, ASAAS_API_KEY, ASAAS_WEBHOOK_URL, ASAAS_WEBHOOK_TOKEN, ASAAS_WEBHOOK_MAX_BODY_BYTES e ASAAS_WEBHOOK_PROCESS_LIMIT.
10. Confirmar que ASAAS_WEBHOOK_TOKEN tem 32–255 caracteres, não possui espaços e é diferente da API key.
11. Conferir o SHA-256 do ZIP contra o manifesto antes da extração.

## Fase B — Banco

1. Manter o worker Asaas interrompido durante a mudança de schema.
2. Executar o preflight no banco correto:

~~~sql
SELECT current_database(), current_setting('TimeZone');
SELECT TO_REGCLASS('public.usuarios');
SELECT TO_REGCLASS('public.proprietarios');
SELECT TO_REGCLASS('public.chacaras');
SELECT TO_REGCLASS('public.mensalidades_anuncios');
SELECT TO_REGCLASS('public.cobrancas_mensalidades');
SELECT TO_REGCLASS('public.asaas_webhook_eventos');
~~~

3. Se qualquer dependência retornar NULL, interromper. Não executar migrations completas de mensalidade ou Asaas sem comparação do schema.
4. Revisar e executar somente o consolidado, parando no primeiro erro:

~~~bash
psql -X -v ON_ERROR_STOP=1 \
  --host="<DB_HOST>" --port="<DB_PORT>" --username="<DB_USER>" --dbname="<DB_NAME>" \
  --file="affiliate_production_migration.sql"
~~~

5. A migration é transacional e usa advisory lock. Qualquer erro deve reverter a transação.
6. Validar estruturas, códigos, constraints e índices:

~~~sql
SELECT TO_REGCLASS('public.afiliados');
SELECT TO_REGCLASS('public.configuracoes_comissao_afiliados');
SELECT TO_REGCLASS('public.comissoes_afiliados');
SELECT TO_REGCLASS('public.pagamentos_afiliados');
SELECT TO_REGCLASS('public.alocacoes_pagamentos_afiliados');
SELECT TO_REGCLASS('public.ajustes_afiliados');

SELECT formatar_codigo_afiliado(9999), formatar_codigo_afiliado(10000);

SELECT conname, convalidated
FROM pg_constraint
WHERE conrelid IN (
  'afiliados'::REGCLASS,
  'proprietarios'::REGCLASS,
  'comissoes_afiliados'::REGCLASS,
  'pagamentos_afiliados'::REGCLASS,
  'alocacoes_pagamentos_afiliados'::REGCLASS,
  'ajustes_afiliados'::REGCLASS
)
ORDER BY conrelid::REGCLASS::TEXT, conname;

SELECT schemaname, tablename, indexname
FROM pg_indexes
WHERE tablename IN (
  'afiliados',
  'proprietarios',
  'comissoes_afiliados',
  'pagamentos_afiliados',
  'alocacoes_pagamentos_afiliados',
  'ajustes_afiliados'
)
ORDER BY tablename, indexname;
~~~

7. Confirmar exatamente uma configuração global, com percentual entre 1 e 10000 basis points.
8. Não inserir afiliado, proprietário, chácara, comissão ou pagamento por SQL.

## Fase C — Arquivos

1. Colocar alugfacil_afiliados_producao.zip em diretório temporário fora do document root.
2. Comparar o conteúdo com affiliate_deploy_manifest.txt.
3. Extrair na raiz real do projeto; o ZIP não possui pasta-raiz adicional:

~~~bash
unzip -o alugfacil_afiliados_producao.zip -d /caminho/da/raiz/alugfacil
~~~

4. Não apagar arquivos e não substituir .env.
5. Restaurar proprietário, grupo e permissões usados pela instalação.
6. Garantir escrita somente onde já é necessária, especialmente storage/logs.
7. Confirmar que public/index.php e public/webhook_asaas.php estão no document root correto.
8. Executar lint nos PHP extraídos antes de liberar tráfego.

## Fase D — Webhook

1. Endpoint esperado: https://<dominio>/webhook_asaas.php
2. Confirmar HTTPS válido e POST público somente nesse endpoint.
3. Configurar o mesmo ASAAS_WEBHOOK_TOKEN no Asaas e no .env, enviado pelo header asaas-access-token.
4. Manter habilitados:

   - PAYMENT_CREATED
   - PAYMENT_UPDATED
   - PAYMENT_CONFIRMED
   - PAYMENT_RECEIVED
   - PAYMENT_OVERDUE
   - PAYMENT_DELETED
   - PAYMENT_REFUNDED
   - PAYMENT_PARTIALLY_REFUNDED
   - PAYMENT_REFUND_IN_PROGRESS
   - PAYMENT_CHARGEBACK_REQUESTED
   - PAYMENT_CHARGEBACK_DISPUTE
   - PAYMENT_AWAITING_CHARGEBACK_REVERSAL

5. O endpoint apenas autentica, valida e persiste. Os efeitos financeiros ocorrem no worker.
6. Não usar scripts/configure-asaas-webhook.php em produção; ele é restrito ao Sandbox.

## Fase E — Worker

1. Dependências: PHP CLI, bootstrap, .env de produção, PostgreSQL, asaas_webhook_eventos e services do projeto.
2. Fazer primeiro uma leitura sem mutação:

~~~bash
cd /caminho/da/raiz/alugfacil
php scripts/process-asaas-webhooks.php --limit=50 --retry-errors --dry-run
~~~

3. Quando o dry-run estiver correto, executar um lote pequeno:

~~~bash
php scripts/process-asaas-webhooks.php --limit=50 --retry-errors
~~~

4. Com zero eventos, informa “Total selecionado: 0” e retorna código 0.
5. Código 1 indica pelo menos um evento com erro. Falhas fatais também retornam código não zero. Em development, banco local incorreto retorna 2.
6. Cada evento usa transação, FOR UPDATE SKIP LOCKED, status persistido e idempotência.
7. O script não possui lock global de processo. Usar flock, systemd timer ou scheduler singleton.

## Fase F — Cron

Frequência recomendada: uma vez por minuto, com lock não bloqueante:

~~~cron
* * * * * flock -n /var/lock/alugfacil-asaas-webhooks.lock php /caminho/da/raiz/alugfacil/scripts/process-asaas-webhooks.php --limit=100 --retry-errors >> /caminho/da/raiz/alugfacil/storage/logs/asaas-worker.log 2>&1
~~~

1. Substituir caminhos após conferência.
2. O usuário do cron deve ter acesso somente ao necessário.
3. Se flock não estiver disponível, usar scheduler singleton. Não habilitar execuções concorrentes sem proteção externa.
4. Monitorar o código de saída e o crescimento da fila.

## Fase G — Smoke test funcional

Sem cobrança financeira real:

1. Abrir o painel administrativo.
2. Confirmar o menu Afiliados.
3. Cadastrar afiliado de homologação somente com autorização formal para criar esse dado em produção.
4. Confirmar código AFxxxx e link /cadastro-proprietario?ref=AFxxxx.
5. Testar login e logout do afiliado.
6. Abrir dashboard, indicados, comissões e perfil.
7. Confirmar estados vazios sem warnings.
8. Testar cadastro de proprietário pelo link somente com autorização formal.
9. Confirmar o vínculo administrativo.
10. Confirmar que chácara do indicado exige mensalidade e rejeita SEM_MENSALIDADE.
11. Confirmar que proprietário comum continua podendo operar com ou sem mensalidade.
12. Não criar pagamento, comissão, ajuste ou cobrança apenas para esse smoke.

## Fase H — Validação financeira controlada

Somente após o smoke básico:

1. Usar Sandbox Asaas ou mecanismo controlado aprovado.
2. Confirmar persistência única em asaas_webhook_eventos.
3. Confirmar processamento pelo worker.
4. Confirmar uma única cobrança e comissão para o mesmo asaas_payment_id.
5. Confirmar liberação em sete dias e ausência de duplicidade entre PAYMENT_CONFIRMED e PAYMENT_RECEIVED.
6. Não executar transação real automaticamente durante o deploy.

## Fase I — Monitoramento

1. Monitorar logs PHP/FPM ou Apache/Nginx.
2. Monitorar storage/logs/app.log e a saída do worker sem expor payloads ou segredos.
3. Acompanhar a fila:

~~~sql
SELECT status_processamento, COUNT(*)
FROM asaas_webhook_eventos
GROUP BY status_processamento
ORDER BY status_processamento;
~~~

4. Investigar eventos em erro, divergente ou processando por tempo anormal.
5. Confirmar HTTP 200 no recebimento legítimo e 401 para token inválido.
6. Monitorar erros de constraint, falhas de login e HTTP 500.

## Rollback

Se houver problema antes de transações financeiras reais:

1. Interromper cron/worker.
2. Pausar o webhook ou impedir temporariamente seu processamento, preservando eventos recebidos.
3. Restaurar os arquivos a partir do backup.
4. Restaurar o banco completo se a migration tiver produzido comportamento incorreto.
5. Validar a restauração antes de reabrir tráfego.
6. Não executar DROP TABLE, TRUNCATE ou scripts destrutivos improvisados.

Se já houver comissões, pagamentos ou ajustes reais, não restaurar o banco isoladamente sem conciliação financeira e aprovação formal.

## Critério de conclusão

O deploy só termina quando schema, arquivos, webhook, worker, smoke funcional e monitoramento estiverem aprovados e documentados.
