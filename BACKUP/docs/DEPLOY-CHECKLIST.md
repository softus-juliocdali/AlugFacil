# Checklist de deploy e homologação

## Antes

- Faça backup PostgreSQL e registre `git rev-parse HEAD` na VPS.
- Valide `.env`, PHP/cURL/PDO PgSQL, espaço em disco, domínio e HTTPS.
- Confirme `APP_ENV=staging`, Asaas Sandbox e ausência de credenciais de produção.

## Deploy

```bash
git pull --ff-only
php -v
php database/apply_asaas_sandbox_homologation_migration.php
find app database public scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/asaas-health-check.php
php scripts/configure-asaas-webhook.php
```

O projeto não possui Composer, Docker, cache de framework, Supervisor ou unidade systemd versionados. Preserve o DocumentRoot em `public/` e conceda escrita somente ao usuário do PHP em `storage/logs` e uploads. Recarregue apenas o serviço web/PHP-FPM realmente usado pela VPS.

## Depois

- Home e login retornam HTTP 200; autenticação e checkout funcionam.
- A cobrança é PIX Sandbox, sem split, e o webhook HTTPS autentica e confirma a reserva.
- Logs não contêm segredos e não houve chamada a `api.asaas.com`.

## Rollback

Pare novas requisições, registre logs, volte o código ao commit anterior conhecido com o procedimento Git adotado pela operação e restaure o backup do banco se a migration precisar ser revertida. As colunas novas são aditivas; não use `DROP` durante incidente. Reative somente após smoke de home/login. 
