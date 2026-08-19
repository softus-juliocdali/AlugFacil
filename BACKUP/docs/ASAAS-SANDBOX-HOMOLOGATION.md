# Homologação Asaas Sandbox

## Preparação

No `.env`, configure `ASAAS_ENVIRONMENT=sandbox`, `ASAAS_BASE_URL=https://api-sandbox.asaas.com/v3`, a chave Sandbox, uma URL HTTPS pública terminando em `/webhook_asaas.php`, um token exclusivo de 32 a 255 caracteres e `ASAAS_ALLOW_SANDBOX_MUTATIONS=true`. Nunca use credenciais de produção.

Execute `php scripts/asaas-health-check.php` e depois `php scripts/configure-asaas-webhook.php`. O health check apenas consulta a conta e não cria movimentações.

## Teste ponta a ponta

1. Entre pelo navegador e crie uma reserva nova.
2. Confira: valor da reserva + R$ 2,00 de taxa PIX = total; o proprietário recebe o valor integral da reserva.
3. Finalize o checkout e clique em **Gerar PIX**.
4. Confira QR Code/copia e cola, valor, vencimento e status.
5. Confirme no painel Asaas Sandbox que a cobrança PIX existe e que o `externalReference` é `reserva_<id>`.
6. Use no painel Sandbox a confirmação fictícia de pagamento.
7. Confirme o recebimento HTTPS e processe a fila com `php scripts/process-asaas-webhooks.php`.
8. No banco, valide reserva e pagamento confirmados, ausência de novas linhas em `asaas_splits` e repasse em `aguardando_liberacao` ou `liberado_para_repasse`, respeitando `repasse_liberavel_em`.
9. Mantenha `php scripts/processar-repasses-reservas.php` em dry-run. Não faça transferência nem reembolso externo.
10. Confira nos logs `reserva_id`, `payment_id`, evento, resultado e horário, sem segredos.

Ao terminar, retorne `ASAAS_ALLOW_SANDBOX_MUTATIONS=false`. A confirmação da reserva depende do webhook, nunca do navegador.
