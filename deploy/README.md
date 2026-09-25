# Código reproduzível da instalação web

O commit contém os fontes executados pela VPS, incluindo checkout PIX/boleto,
autorização, idempotência, regras financeiras e API chamada pelo front controller.
Os bytes dos fontes são preservados por `.gitattributes`, pois os certificados
operacionais existentes usam hashes de arquivos. Não substituir fontes por backups.

Após checkout/deploy, execute `php scripts/verify-source.php`. O resultado esperado
é `code_divergences: 0`. O manifesto verifica conteúdo e código extra nos diretórios
da aplicação, sem acessar banco ou Asaas.

## Estado externo obrigatório

- `.env`: conexão PostgreSQL, credenciais, chaves de criptografia e flags existentes.
- Arquivo privado apontado por `FINANCIAL_TEST_REGISTRY`: identidades de teste,
  autorização e vencimento. Não é código nem altera arquivos da aplicação.
- Perfis, certificados e manifests privados usados pelos workers, mais o marcador
  `.financial-release.json`. Devem permanecer com permissões restritas.
- PostgreSQL existente com schema financeiro e dados, uploads, sessões e logs.
- Configuração nginx/PHP-FPM, tarefas agendadas, TLS e permissões do servidor.

O checkout Git reproduz o **código**; não copia dados de clientes, segredos ou banco.
Reutilize esse estado externo na restauração desta instalação. Não rode o SQL de
instalação limpa nem migrations sobre o banco existente como parte deste deploy.

`deliverables/financial-release-20260924/migrations.json` é o catálogo declarativo
de schema usado pelo gerador existente; apenas esse arquivo é fonte versionada
nesse diretório. Backups, evidências e manifests gerados não fazem parte da release.

As restrições atuais de Sandbox e de perfis autorizados são mantidas. Esta
consolidação não habilita cobranças reais, transferências nem novos meios de pagamento.
