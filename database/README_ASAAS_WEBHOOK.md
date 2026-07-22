# Webhook Asaas

Aplicar e verificar somente no ambiente local permitido:

```sh
php database/apply_asaas_webhook_migration.php
php database/verify_asaas_webhook.php
```

O aplicador recusa qualquer banco diferente de `alugfacil_dev`. A migration e transacional, aditiva e pode ser repetida. Ela nao altera reservas ou pagamentos existentes.

Processamento futuro por cron (nao configurado por esta entrega):

```sh
php scripts/process-asaas-webhooks.php --limit=50 --retry-errors
```

Antes de publicar, defina um `ASAAS_WEBHOOK_TOKEN` exclusivo, aleatorio e com pelo menos 32 caracteres. Ele nao pode ser a API key. Configure o mesmo valor no header `asaas-access-token` do webhook no Sandbox Asaas.

Rollback manual, somente se a tabela estiver vazia e apos backup/auditoria: remova primeiro os tres indices `idx_asaas_webhook_*` e depois a tabela `asaas_webhook_eventos`. O rollback nao e automatizado para evitar perda acidental dos payloads de auditoria.
