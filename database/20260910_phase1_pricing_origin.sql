-- Additional incompatibility reproduced by the real reservation INSERT after fixing boolean binding.
ALTER TABLE reservas ALTER COLUMN origem_precificacao TYPE VARCHAR(40);
COMMENT ON COLUMN reservas.origem_precificacao IS 'Identificador versionado do motor que produziu o snapshot; nao e um status de pagamento.';
