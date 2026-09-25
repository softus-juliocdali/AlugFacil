# Cartão mensal: captura aprovada no Asaas Sandbox

Execução em 25/09/2026, horários America/Sao_Paulo. Escopo: mensalidade do anúncio por cartão e dependências necessárias. Backend do app 0.3.2; nenhum novo binário mobile, publicação em loja ou cobrança em Asaas Production.

**Conclusão: cartão mensal testado e aprovado no Sandbox**, mediante checkout transparente real do AlugFácil. A confirmação assíncrona foi recebida pelo webhook público e processada pelo CLI existente com filtro dos dois eventos reais. O processamento automático pelo timer **continua bloqueado** por manifesto certificado desatualizado; não foi declarado homologado.

## Bloqueio reproduzido e regra preservada

No commit inicial `3e2a0c382602b776cba5661105afafce3858b601`, `app/services/FinancialReleasePolicy.php::assertMonthlyPaymentsAllowed()` recusava incondicionalmente a mensalidade. `assertExternalMutationAllowed()` também não autorizava emissão nem captura mensal. Os chamadores eram `MonthlyCardPayment::pay()` e `MonthlyBillingService::issue()`.

Às 19:15:58, o checkout respondeu com redirecionamento HTTP 302 e mensagem: “Mensalidades bloqueadas neste ambiente: a política Sandbox autoriza apenas cobranças de reservas por PIX/boleto. Nenhuma cobrança mensal foi enviada.” A obrigação sintética 1 permaneceu `pendente`, sem meio escolhido, payment ID ou operação financeira. **Não existiu resposta Asaas nessa etapa**, pois o bloqueio antecedeu o gateway.

Essa era uma proteção técnica de homologação introduzida na execução anterior, não uma proibição comercial do cartão: as regras comerciais documentadas admitem mensalidade por PIX ou cartão. Foram preservados valor global, elegibilidade, snapshots, vínculo do proprietário e política geral de congelamento financeiro. A correção acrescenta autorização privada, explícita e expiráveis por obrigação sintética, com correspondência exata de usuário/proprietário/imóvel/valor e imóvel operacionalmente indisponível. Não libera mensalidades de usuários reais, PIX, transferências ou repasses.

O ambiente efetivo usa `sandbox-test`, endpoint `https://api-sandbox.asaas.com/v3`, mutações Sandbox habilitadas e mensalidade global ativa de **11990 centavos, versão 1**. A autorização temporária foi removida após os testes; novas mensalidades não autorizadas continuam bloqueadas deliberadamente. Liberar pagamentos gerais exigiria uma decisão de release financeiro e homologação correspondente, não outra exceção de teste.

## Cenário e execução real

Foram criados exclusivamente dois perfis sintéticos. Nenhuma obrigação de proprietário real foi modificada. Os imóveis ficaram indisponíveis durante toda a execução. As obrigações foram geradas por `CommercialConfigurationService::monthlyObligation()` com a configuração vigente, sem reduzir o preço ou editar snapshots. Vencimento: 25/09/2026; valor: **R$ 119,90**, superior ao mínimo de R$ 15 documentado pela homologação anterior desta conta.

O primeiro cenário (usuário 16, proprietário 8, imóvel 6, obrigação 1) chegou ao cadastro de cliente no gateway e recebeu **HTTP 400 `invalid_mobilePhone`**, operação 6623 recusada, uma tentativa. Não houve cobrança nem captura. A intenção recusada foi preservada; não foi zerada ou reenviada.

O cenário corrigido (usuário 17, proprietário 9, imóvel 7, obrigação 2) usou contato de exemplo da documentação Asaas com `notificationDisabled=true`, CPF sintético válido, senha aleatória e cartão fictício documentado para Sandbox. Dados de cartão existiram somente em memória durante o envio TLS; não estão nestas evidências.

Um cliente HTTP automatizado executou os mesmos endpoints do checkout WebView: login mobile (200), emissão de ticket (200), troca de sessão em `/mobile/entrar` (303), GET do formulário e POST autenticado/CSRF em `/mobile/mensalidades/2` (302), seguido de GET (200). Nenhuma página hospedada Asaas foi aberta. Este teste exercitou o backend e o HTML reais publicados; não foi uma nova execução instrumentada do APK ou de iOS.

Às **19:18:24**, o Asaas confirmou:

| Evidência | Resultado |
|---|---|
| Payment ID | `pay_wbznx0ad3s4ys4b7` |
| Gateway | Sandbox, HTTP 200, `CREDIT_CARD`, `CONFIRMED`, 119.90 |
| Cadastro cliente | Operação 6624, concluída, HTTP 200, 1 tentativa |
| Emissão mensal | Operação 6625, concluída, HTTP 200, 1 tentativa |
| Captura transparente | Operação 6626, concluída, HTTP 200, 1 tentativa |
| Consulta por referência externa | 1 cobrança; `hasMore=false` |
| Tela após captura | “Pago”; formulário de cartão ausente |

Não foi usado endpoint de confirmação manual do Sandbox, gateway fake ou atualização SQL para marcar esta cobrança como paga. `CONFIRMED` comprova aprovação/captura; não se declara liquidação `RECEIVED`.

## Webhook e baixa interna

O webhook existente estava habilitado, sem interrupção, apontando para `https://alugfacil.net.br/webhook_asaas.php`. A API omitiu seu segredo (`token_returned=false`), portanto uma comparação prévia de token não era conclusiva. O recebimento efetivo no endpoint autenticado comprovou a entrega:

| Evento real | Recebido | Processado | Tentativas |
|---|---|---|---|
| `evt_05b708f961d739ea7eba7e4db318f621&20223615` — `PAYMENT_CREATED` (interno 37) | 19:18:23 | 19:26:22 | 1 |
| `evt_15e444ff9b9ab9ec29294aa1abe68025&20223620` — `PAYMENT_CONFIRMED` (interno 38) | 19:18:26 | 19:26:22 | 1 |

Foi encontrado e corrigido um defeito necessário ao cartão mensal: `SandboxFinancialWorker` só selecionava reservas. Agora seleciona também eventos de cartão mensal de obrigações sintéticas explicitamente autorizadas, inclusive eventos posteriores à confirmação. Essa ramificação não emite, captura, estorna nem transfere valores.

Há, contudo, um bloqueio independente e anterior na entrada do serviço `alugfacil-sandbox-financial.service`: `/etc/alugfacil/run-worker.php` chama `scripts/financial-sandbox-worker.php`, cuja verificação de manifesto falha com **`Release file checksum mismatch: app/api/MobileAuthApi.php`**. A leitura diagnóstica como `www-data` confirmou registro válido, mutações habilitadas e identidade do banco correta; a falha ocorre na validação do manifesto `/etc/alugfacil/manifest.json`, antes do worker. Seus hashes fixados e certificado não foram alterados. Não se removeu essa proteção.

Para validar a obrigação sintética, foi usado `scripts/process-asaas-webhooks.php --event-id=<evento real>` separadamente para os eventos 37 e 38. Não houve evento fabricado ou alteração manual da baixa. Resultado: **obrigação `paga`, cobrança `PAGA`, mensalidade `EM_DIA`**, com vínculo ao evento de confirmação real. A execução automática ainda requer atualização formal dos artefatos certificados do release; não se ampliou esta tarefa para recertificar outros fluxos.

## Repetição, segurança e limpeza

- POST repetido às 19:21:57, antes da baixa interna: reutilizou a operação de captura concluída, ainda com 1 tentativa.
- POST repetido após a baixa às 19:26:23 e reabertura com nova sessão às 19:26:25: tela “Pago”, mesma cobrança, emissão e captura ainda com 1 tentativa cada.
- Reprocessamento pelo filtro do mesmo evento: `Total selecionado: 0`; ambos eventos permaneceram com 1 processamento.
- Auditoria dos 9 documentos de intenção/resultado/webhook do teste: zero campos proibidos de cartão/segredos e zero PAN. Zero ocorrências do PAN fictício nos arquivos conhecidos `storage/logs/app.log`, `nginx/access.log` e `nginx/error.log` examinados.
- Registro privado restaurado exatamente ao conteúdo original, removendo ambas as autorizações temporárias.
- Senhas aleatórias invalidadas, sessões/tokens revogados, arquivos temporários de credenciais/cookies apagados. Sessão anterior recebeu redirecionamento 302 após revogação.
- Ambos perfis sintéticos bloqueados, imóveis indisponíveis, geração de novas mensalidades desativada exclusivamente nesses imóveis. Obrigação 1 sem cobrança cancelada; obrigação 2 paga preservada.
- Foram mantidos registros financeiros sintéticos, cliente/cobrança Sandbox e eventos sanitizados necessários à rastreabilidade. Não se estornou ou apagou a cobrança aprovada para produzir uma aparência artificial de limpeza. Não houve dinheiro real.

## Validação e publicação

Passaram `monthly_card_release_policy_test.php`, `monthly_card_worker_test.php`, `monthly_card_payment_test.php`, `financial_architecture_test.php`, `phase4_commercial_test.php`, `phase9_webhook_contract_test.php` e `financial_error_message_test.php`. Incluem isolamento de autorização, snapshots, concorrência, timeout, resultado desconhecido, recusa, repetição, ausência de cartão nas intenções, estado mensal e eventos posteriores. São testes locais complementares; a aprovação acima vem de chamadas reais ao Sandbox.

A suíte preexistente `financial_failure_modes_test.php` falhou porque seu `TimeoutClient` antigo não implementa `accountScope()` requerido por `ProvisionamentoAsaasService` e sua fixture consulta onboarding anterior. Esses arquivos não foram alterados; a falha foi registrada, sem ampliar o escopo para onboarding. Não se afirma que todas as suítes do repositório passaram.

Correções publicadas em `407dede9f1c8079b6759cdd4df05b68c13ea0a55` e `fb98fc52e8c663ee2c54bcf25ed13ac99463d3e0`. O commit de evidências posterior não muda código executável. Cada deploy usou backup do código, fast-forward do Git remoto, manifesto `deploy/source.sha256` (307 arquivos, zero divergências), lint PHP, conferência de `.env` inalterado e reload do PHP-FPM. `/api/v1/health` retornou HTTP 200/status ok. Nenhuma migration foi aplicada. Git local/remoto/VPS conferidos novamente na conclusão.

Evidências sanitizadas completas e hashes: [diretório de evidências](evidence/monthly-card-sandbox-20260925/SHA256SUMS). O arquivo `capture-result.txt` registra a recusa do contato inicial; `capture2-result.txt` registra a aprovação efetiva. `worker-diagnosis.txt` documenta a limitação do agendador; `webhook-and-repetition-final.txt` comprova a baixa e idempotência.

Referências oficiais: [cartão no Sandbox](https://docs.asaas.com/docs/faq-sandbox), [cadastro de clientes](https://docs.asaas.com/docs/criando-um-cliente) e [pagamento de cobrança existente por cartão](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito).
