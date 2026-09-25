# Pendências da versão 0.3.2 — execução de 25/09/2026

Versão entregue: **0.3.2**, Android `versionCode=6`, iOS `buildNumber=6`.
Escopo restrito a cartão mensal, PIX mensal, favoritos e validação iOS/Android.

## Resultado dos quatro itens

| Item | Estado | Evidência e limite |
| --- | --- | --- |
| Cartão mensal transparente | **Pendente de homologação externa** | Formulário dentro da WebView privada e captura de cobrança existente implementados. 19 verificações PostgreSQL/gateway simulado passaram, incluindo concorrência, timeout, recusa, reabertura e ausência de PAN/token persistidos. A política efetiva da VPS proíbe emissão mensal e captura de cartão; não foi alterada para liberar essas operações. |
| PIX mensal transparente | **Pendente de emissão/homologação externa** | Exibição de QR, copia e cola, valor, vencimento e estado testada com gateway simulado; ação repetida reutiliza a cobrança. Consulta real à wallet Asaas Sandbox passou. A VPS não possui obrigações mensais vinculadas e sua política exclui emissão mensal; nenhum PIX indevido foi criado. |
| Favoritos | **Concluído** | Salvar/remover idempotentes, lista e abertura do imóvel na conta autenticada, mesma tabela da web. Testados por HTTP local, API publicada e APK oficial; favorito preservado após reiniciar o aplicativo e após novo login. |
| Execução iOS | **Pendente** | Build para simulador concluído e artefato inspecionado: `com.alugfacil.cliente`, 0.3.2/6, `iphonesimulator`. Não há Mac, simulador Apple ou dispositivo Apple acessível nesta execução Windows. Compilação não foi tratada como execução/homologação iOS. |

## Restrição financeira comprovada

Leitura da VPS às 18:17 de 25/09/2026 (America/Sao_Paulo):

- `APP_ENV=production`, gateway `sandbox`, base `https://api-sandbox.asaas.com/v3`.
- `FINANCIAL_RELEASE_MODE=sandbox-test`, mutações Sandbox habilitadas sob lista restrita.
- Registro privado válido até 01/10/2026 22:38:36 UTC; três usuários, um proprietário e um imóvel autorizados.
- Mensalidade global ativa, valor **11990 centavos**, versão **1**.
- **Zero registros em `obrigacoes_mensalidades`**. Não há QR mensal existente que possa ser homologado nesse banco.
- `FinancialReleasePolicy::assertExternalMutationAllowed` autoriza criação de cobrança apenas para `reserva-obrigacao`, PIX/BOLETO; mensalidade e captura por cartão estão fora da lista.
- GET da wallet retornou com sucesso usando a configuração real. Nenhuma mutação de gateway foi solicitada.

O novo pré-check recusa a operação antes de selecionar meio, criar customer ou registrar envio. Não altera flags, lista de autorizados, condições comerciais ou regras financeiras. Não houve cobrança real, simulação de liquidação externa, refund ou transferência.

## Implementação e segurança

Cartão usa `POST /payments/{id}/payWithCreditCard`, conforme a [referência oficial Asaas](https://docs.asaas.com/reference/pay-a-charge-with-credit-card). Captura não abre fatura hospedada. O serviço valida proprietário, valor, meio, customer e referência persistida; usa a intenção financeira existente, com lock PostgreSQL. Resultado desconhecido bloqueia novo POST e só é recuperado por consulta. Recusa definitiva exige conciliação/suporte, sem tentativas cegas. A confirmação financeira segue os eventos existentes.

PAN, CVV, dados do titular e token não compõem o payload persistido de captura. Respostas são filtradas pelo serviço existente; argumentos sensíveis são protegidos em traces. Formulário sem preenchimento persistido, CVV mascarado, CSRF, HTTPS em produção e `Cache-Control: no-store`. Os dados são descartados após a requisição; reabertura somente consulta.

Favoritos usa Bearer, usuário derivado da sessão e a tabela `favoritos_chacaras`. Salvar/remover expressam estado desejado, em vez de alternar em cada repetição. Lista mostra imóveis publicamente elegíveis. Cache privado separado por conta e removido na saída.

## Verificações executadas

- Mobile: `npm run typecheck`, `npm run lint`, `npm test`: **47 passaram, 4 opcionais ignorados**.
- `node scripts/security-check.mjs`: 622 arquivos/bundles examinados; nenhum valor privado conhecido do `.env` do backend encontrado (checagem estática por correspondência).
- `tests/monthly_card_payment_test.php`: 19 verificações, com duas conexões PostgreSQL concorrentes, timeout antes/depois de captura, recusa, reutilização, autorização e bloqueio sem efeitos colaterais.
- `tests/mobile_owner_integration_test.php`: favoritos, renovação de sessão, isolamento, ponte WebView, ticket único, áreas do proprietário, edição/fotos/calendário, separação entre checkout de hóspede e gestão, CSRF e mensalidade transparente.
- `tests/mobile_auth_postgresql_test.php`: login/cadastro, renovação, concorrência, revogação, isolamento das sessões web/mobile e regressão web.
- `tests/owner_web_payment_test.php`: 16 verificações de preferências de pagamento do proprietário.
- `tests/phase7_finance_test.php`: 32 verificações de cobrança, liquidação, cancelamento/refund, agenda, repasses e locks; gateway simulado.
- `tests/financial_error_message_test.php` e `tests/reservation_transparent_checkout_test.php`: mensagens seguras e preservação de PIX/boleto/cartão das reservas.
- Formulário mensal renderizado em 320, 390 e 768 px com CSS do painel; sem overflow horizontal, CVV mascarado e cartão vazio.
- API publicada: login cliente/proprietário, salvar duas vezes, consultar, isolamento de conta, novo login mantendo favorito, remover duas vezes e logout.

### APK oficial

Instalado no emulador Android API 36. Assinatura validada com `apksigner`, versão instalada conferida por `dumpsys package`.

Testados: abertura, catálogo, detalhe, login de cliente, favoritar, lista, reinício do processo com sessão/favorito restaurados, abrir detalhe pela lista, desfavoritar, histórico integrado do cliente, retorno, logout, login de proprietário, menu nativo, dashboard, mensalidades, recebimentos e imóveis. Contas temporárias sem reservas/imóveis próprios: listas vazias são esperadas. Dados de contas existentes não foram modificados.

Ao final, logout confirmado, processo Android ativo e nenhum registro de crash de `com.alugfacil.cliente` no buffer de crashes consultado. As duas contas temporárias, seus tokens/favoritos e credenciais geradas foram removidos. Um aviso inicial de falta de resposta do System UI do emulador foi dispensado antes dos testes; não era um crash do app.

A tela de mensalidades abriu na WebView. A execução nativa de uma obrigação mensal com QR/captura não pôde ser testada porque não há obrigação no ambiente e a política impede emissão. O formulário e o serviço foram verificados localmente, sem representar homologação real no gateway.

## Builds internos concluídos

- [Android — página EAS](https://expo.dev/accounts/softus-digital/projects/alugfacil-mobile/builds/2829d8a2-bb6d-4c30-b93b-042307d26b27)
- [Android — APK](https://expo.dev/artifacts/eas/1eJ1YRP8Ni4-tjsBVdc3IjPRVbPQeCg-OF8FoHqOdjE.apk)
- [iOS simulador — página EAS](https://expo.dev/accounts/softus-digital/projects/alugfacil-mobile/builds/f8143439-b93a-4f92-89ec-3a0987e00db8)
- [iOS simulador — artefato](https://expo.dev/artifacts/eas/SWEZyuxm_qqRSJfk0kuX2hJnLw4lZqJUr524Feho3DE.tar.gz)

SHA256 Android: `dbc26518dcf14a3f343cd0298efae37f25353b14fba33f212fbc77c3e54e307b`.

SHA256 iOS: `d8707509ecef769a3cf78e2f517e32ece70f606182dc8e3682a9b8545210cfe2`.

Os uploads EAS incluíram os fontes móveis desta alteração antes do commit; o campo `gitCommitHash` dos jobs conserva o HEAD anterior `637d9425ac465b58f0d84b644b312a57564ac67f`. Os fontes móveis enviados foram posteriormente commitados em `f7c59dbfff22f0c5c2847e1283ef1be6d1ae1c11`; o APK instalado demonstra as novas telas. O ajuste visual posterior é PHP servido pela VPS e não altera os bundles móveis. Nenhum submit para App Store/Google Play foi executado.

## Git, publicação e evidências

Implementação em `f7c59dbfff22f0c5c2847e1283ef1be6d1ae1c11`, enviada a `origin/master` e publicada por fast-forward na VPS. Código anterior arquivado antes da atualização; `.env` preservado por hash. Manifesto: **307 arquivos, zero divergências**. Sem migrations ou alteração de condições comerciais.

O commit que contém este relatório também publica o ajuste responsivo do formulário e seu manifesto atualizado. O hash final sincronizado é registrado na resposta de entrega e pode ser conferido com `git rev-parse HEAD` nas três cópias.

Evidências locais ignoradas pelo Git: `storage/tmp/mobile-032-completion/`, contendo auditoria financeira sanitizada, resultado da API publicada, metadados/builds e capturas Android/formulário. Arquivos locais anteriores fora desta tarefa foram preservados.
