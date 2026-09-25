-- Complete legacy rows without a ledger entry, including subscriptions with other already-paid cycles.
ALTER TABLE cobrancas_mensalidades DISABLE TRIGGER snapshot_afiliado_cobranca;
UPDATE cobrancas_mensalidades cm SET afiliado_id_snapshot=p.afiliado_id,percentual_afiliado_bps_snapshot=COALESCE(a.percentual_comissao_bps,0)
 FROM mensalidades_anuncios m JOIN proprietarios p ON p.id=m.proprietario_id LEFT JOIN afiliados a ON a.id=p.afiliado_id
 WHERE cm.mensalidade_id=m.id AND cm.percentual_afiliado_bps_snapshot IS NULL
 AND NOT EXISTS(SELECT 1 FROM comissoes_afiliados co WHERE co.cobranca_mensalidade_id=cm.id);
ALTER TABLE cobrancas_mensalidades ENABLE TRIGGER snapshot_afiliado_cobranca;
