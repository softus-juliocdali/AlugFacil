ALTER TABLE cobrancas_mensalidades
 ADD COLUMN refund_estado VARCHAR(24) NOT NULL DEFAULT 'nenhum'
  CHECK(refund_estado IN ('nenhum','solicitado','processando','conciliacao_manual','concluido','falhou')),
 ADD COLUMN refund_referencia CHAR(64),
 ADD COLUMN refund_bloqueado BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE cobrancas_mensalidades ADD CONSTRAINT refund_pendente_bloqueado
 CHECK(refund_estado NOT IN ('solicitado','processando','conciliacao_manual') OR refund_bloqueado);
-- Historical reversals may have been caused by a nonterminal event. Do not undo ledger
-- entries or invent conclusive evidence: preserve them and require reconciliation.
UPDATE cobrancas_mensalidades SET refund_estado='conciliacao_manual',refund_bloqueado=TRUE
 WHERE status='ESTORNADA';
CREATE INDEX cobrancas_refund_bloqueado ON cobrancas_mensalidades(id) WHERE refund_bloqueado;
