-- Legacy checkout timestamps were local civil times in the application's Sao Paulo timezone.
ALTER TABLE reservas ALTER COLUMN expira_em TYPE TIMESTAMPTZ USING expira_em AT TIME ZONE 'America/Sao_Paulo';
