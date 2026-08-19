# Onboarding financeiro Asaas nao-BaaS

Configuracao segura por padrao: as tres flags de criacao e enforcement permanecem desabilitadas no `.env.example`. A criacao externa e somente Sandbox, manual, administrativa, com CSRF, motivo e confirmacao `CRIAR SANDBOX`. Abrir ou salvar telas nunca chama o Asaas.

```sh
php database/apply_financial_onboarding_migration.php
php database/verify_financial_onboarding.php
php tests/financial_onboarding_test.php
php scripts/sync-asaas-subcontas.php --dry-run
```

O sistema persiste apenas account ID e walletId. A `apiKey` retornada na criacao e removida em memoria e nao existe no schema, views, historico ou logs. O texto de aceite e operacional e esta marcado para revisao juridica.

Rollback manual, somente apos backup e se as tabelas estiverem vazias: remover, nessa ordem, `auditoria_dados_financeiros`, `historico_asaas_subcontas`, `asaas_subcontas`, `aceites_financeiros_proprietarios` e `proprietario_dados_financeiros`. Nao ha rollback automatico para evitar perda de auditoria.
