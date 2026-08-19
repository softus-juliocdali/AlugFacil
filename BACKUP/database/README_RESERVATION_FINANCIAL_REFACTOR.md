# Refatoração financeira 07-R

Migration aditiva e reaplicável, exclusiva para `alugfacil_dev`.

```bash
php database/apply_reservation_financial_refactor_migration.php
php database/verify_reservation_financial_refactor.php
```

Não há `DROP TABLE`, `TRUNCATE`, recálculo histórico ou integração externa. As
tabelas `asaas_splits` permanecem como legado descontinuado e não representam
repasses. Mensalidades de proprietários são um módulo futuro separado (PIX ou
cartão, sem split).
