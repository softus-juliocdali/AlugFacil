-- Alug Fácil — VERIFICAÇÃO da estrutura de produção
-- Este arquivo NÃO altera o banco. Execute somente as consultas abaixo.
SELECT current_database() AS banco, current_user AS usuario;
SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema='public' AND table_name='chacaras' AND column_name='tipo_imovel';
SELECT conname, pg_get_constraintdef(oid) AS definicao
FROM pg_constraint
WHERE conrelid='public.chacaras'::regclass AND conname='chk_chacaras_tipo_imovel';
SELECT to_regclass('public.favoritos_chacaras') AS tabela_favoritos;
