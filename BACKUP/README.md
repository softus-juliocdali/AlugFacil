# Alug Fácil

## 1. Nome do projeto

**Alug Fácil**

Sistema web para busca, reserva e administração de locação de chácaras.

## 2. Descrição do sistema

O Alug Fácil é uma aplicação PHP com arquitetura MVC simples. O sistema permite que visitantes pesquisem chácaras, visualizem detalhes, consultem localização e iniciem reservas. Clientes autenticados podem reservar, acompanhar histórico e gerenciar seus dados. Proprietários podem cadastrar chácaras, gerenciar fotos, disponibilidade e faturamento. Administradores acompanham o painel geral e gerenciam usuários e proprietários.

O projeto usa PostgreSQL como banco principal e possui pontos de integração com Google Maps e Asaas Sandbox.

## 3. Tecnologias utilizadas

- PHP 8.1 ou superior
- PostgreSQL 14 ou superior
- PDO com extensão `pdo_pgsql`
- HTML5
- CSS3
- JavaScript puro
- Google Maps Embed API
- Asaas Sandbox API
- Servidor embutido do PHP para desenvolvimento local

## 4. Requisitos do ambiente

- PHP 8.1+
- PostgreSQL 14+
- Extensões PHP habilitadas:
  - `pdo`
  - `pdo_pgsql`
  - `curl`
  - `fileinfo`
  - `mbstring`
- Terminal PowerShell, CMD, Git Bash ou equivalente
- Navegador moderno
- Chave do Google Maps, caso queira usar mapas
- Chave Sandbox do Asaas, caso queira testar pagamentos

## 5. Estrutura de pastas

```text
app/
  config/
    apis.php
    config.php
    database.php
    routes.php
  controllers/
  core/
  helpers/
  models/
  views/
    admin/
    auth/
    cliente/
    layouts/
    proprietario/
    public/
database/
  alugfacil_postgres.sql
  auth_migration.sql
  owner_cpf_migration.sql
public/
  assets/
    css/
    img/
    js/
    uploads/
  index.php
  webhook_asaas.php
README.md
```

Responsabilidades principais:

- `public/index.php`: ponto de entrada da aplicação.
- `app/config/routes.php`: definição das rotas.
- `app/config/database.php`: configuração da conexão PostgreSQL.
- `app/config/apis.php`: configuração de Google Maps e Asaas.
- `app/controllers`: fluxo HTTP, autorização e validação.
- `app/models`: consultas e persistência.
- `app/views`: telas públicas, painéis e layouts.
- `app/helpers`: funções auxiliares, CSRF, Google Maps e Asaas.
- `database`: schema inicial e migrations.
- `public/assets`: CSS, JavaScript, imagens e uploads públicos.

## 6. Como configurar PostgreSQL

Instale o PostgreSQL e crie o banco usado pelo projeto:

Crie um banco e um usuario exclusivos para cada ambiente. Nao registre credenciais reais neste arquivo.

```text
Banco: nome_do_banco
Usuario: usuario_do_banco
Senha: definida_no_ambiente
Host padrao: 127.0.0.1
Porta padrao: 5432
```

O usuário configurado para a aplicação precisa ter permissão para criar tabelas, índices, triggers, funções e inserir dados.

Configuração local sugerida:

```text
Host: 127.0.0.1
Porta: 5432
Banco: alugfacil_dev
Usuário: postgres
Senha: definida_no_arquivo_env_local
```

Esses valores podem ser alterados por variáveis de ambiente.

## 7. Como importar `alugfacil_postgres.sql`

Com o banco criado, importe o arquivo principal:

```bash
psql -U SEU_USUARIO -d SEU_BANCO -f database/alugfacil_postgres.sql
```

No PowerShell, a partir da raiz do projeto:

```powershell
psql -U SEU_USUARIO -d SEU_BANCO -f .\database\alugfacil_postgres.sql
```

O arquivo `database/alugfacil_postgres.sql` faz uma instalação limpa: ele remove tabelas existentes e recria a estrutura inicial. Use com cuidado se já houver dados reais no banco.

Se estiver atualizando uma base antiga, aplique também as migrations disponíveis quando necessário:

```powershell
psql -U SEU_USUARIO -d SEU_BANCO -f .\database\auth_migration.sql
psql -U SEU_USUARIO -d SEU_BANCO -f .\database\owner_cpf_migration.sql
```

## 8. Como configurar conexão com banco

A conexão é configurada em `app/config/database.php` e lê variáveis de ambiente:

```text
DB_DRIVER=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=alugfacil_dev
DB_USER=postgres
DB_PASS=
```

Exemplo no PowerShell:

```powershell
$env:APP_ENV = 'development'
$env:APP_URL = 'http://127.0.0.1:8000'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_NAME = 'alugfacil_dev'
$env:DB_USER = 'postgres'
$env:DB_PASS = 'SUA_SENHA_LOCAL'
```

Para testar a conexão em ambiente de desenvolvimento, rode o servidor local e acesse:

```text
http://localhost:8000/teste-conexao
```

A rota `/teste-conexao` só é registrada quando `APP_ENV=development`.

## 9. Como configurar Google Maps

A chave do Google Maps é lida em `app/config/apis.php` pela variável `GOOGLE_MAPS_API_KEY`:

```powershell
$env:GOOGLE_MAPS_API_KEY = 'SUA_CHAVE_GOOGLE_MAPS'
```

O projeto usa essa chave para montar URLs de mapa no perfil público da chácara. Sem a chave, o sistema continua funcionando, mas os recursos de mapa ficam indisponíveis.

No Google Cloud, habilite a API necessária para o uso do mapa, como Maps Embed API. Configure também restrições de chave por domínio/IP em ambientes públicos.

## 10. Como configurar Asaas Sandbox

A integração Asaas é configurada em `app/config/apis.php` pelas variáveis:

```powershell
$env:ASAAS_API_KEY = 'SUA_CHAVE_SANDBOX_ASAAS'
$env:ASAAS_ENVIRONMENT = 'sandbox'
$env:ASAAS_BASE_URL = 'https://api-sandbox.asaas.com/v3'
```

O helper principal fica em:

```text
app/helpers/AsaasHelper.php
```

O endpoint público de webhook fica em:

```text
public/webhook_asaas.php

## Ciclo de vida das reservas

Defina `RESERVA_EXPIRACAO_MINUTOS=30` no ambiente. Aplique a migration local com
`php database/apply_reservation_lifecycle_migration.php` e verifique com
`php database/verify_reservation_lifecycle.php`.

O mecanismo oficial de expiracao e `php scripts/expire-reservas.php`. Em uma
implantacao futura ele pode ser executado pelo cron a cada cinco minutos, usando
o caminho absoluto da instalacao (sem gravar caminhos de producao no repositorio).

Reservas historicas sem prazo podem ser auditadas, sem alteracao, com
`php scripts/reconcile-pending-reservas.php --dry-run` (o modo padrao tambem e
somente leitura). Uma eventual correcao exige `--apply`, usa a maquina de estados
e continua restrita ao banco local `alugfacil_dev`.

Reservas historicas pendentes nao recebem prazo retroativo. O rollback seguro e
logico: pare o cron e reverta a aplicacao para a versao anterior. Colunas e o
historico devem ser preservados; sua remocao manual so deve ocorrer depois de
backup e verificacao de que nenhuma versao ativa os utiliza.
```

Para receber webhooks localmente, exponha o servidor com uma ferramenta de túnel, como ngrok ou Cloudflare Tunnel, e cadastre no painel Sandbox do Asaas uma URL absoluta parecida com:

```text
https://seu-dominio-ou-tunel/webhook_asaas.php
```

## 11. Como rodar localmente

Na raiz do projeto, execute:

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

Acesse:

```text
http://127.0.0.1:8000
```

Antes de testar login, reservas ou painéis, confirme que o PostgreSQL está rodando e que o arquivo `database/alugfacil_postgres.sql` foi importado.

## 12. Usuário administrador inicial

O schema inicial cria um usuário administrador:

```text
E-mail: definido_no_ambiente
Senha: definida_no_ambiente
```

A senha **deve estar salva no banco com hash**, nunca em texto puro. O campo usado pela tabela `usuarios` é `senha_hash`.

Para gerar o hash no PHP:

```php
password_hash('SENHA_FORTE', PASSWORD_DEFAULT);
```

O login valida a senha com:

```php
password_verify($password, $user['senha_hash']);
```

Após o primeiro acesso, troque a senha do administrador.

## 13. Fluxo básico do sistema

1. Visitante acessa a home pública.
2. Visitante pesquisa ou navega pelas chácaras.
3. Visitante abre o perfil público de uma chácara.
4. Cliente faz cadastro ou login.
5. Cliente cria uma reserva.
6. O sistema cria cobrança no Asaas, se a integração estiver configurada.
7. Cliente acompanha a reserva na área interna.
8. Proprietário cadastra e gerencia suas chácaras.
9. Proprietário controla fotos, disponibilidade e faturamento.
10. Administrador acompanha indicadores e gerencia usuários/proprietários.

## 14. Perfis de usuário

### Cliente

- Pesquisa chácaras.
- Visualiza perfil e localização das chácaras.
- Cria reservas.
- Acompanha histórico.
- Visualiza detalhes das próprias reservas.
- Atualiza dados cadastrais.
- Avalia chácaras após reservas finalizadas.

### Proprietário

- Acessa painel próprio.
- Cadastra, edita e desativa chácaras.
- Gerencia fotos das chácaras.
- Controla disponibilidade.
- Consulta faturamento.
- Atualiza dados cadastrais.

### Administrador

- Acessa dashboard administrativo.
- Lista proprietários.
- Visualiza detalhes de proprietários.
- Ativa ou bloqueia proprietários.
- Lista usuários/clientes.
- Visualiza detalhes de usuários.
- Ativa ou bloqueia usuários.

## 15. Rotas principais

### Públicas e autenticação

- `GET /`
- `GET /chacara/{id}`
- `GET /chacara/{id}/reservar`
- `POST /chacara/{id}/avaliar`
- `GET /login`
- `POST /login`
- `GET /cadastro`
- `POST /cadastro`
- `GET /cadastro-proprietario`
- `POST /cadastro-proprietario`
- `GET /esqueceu-senha`
- `POST /esqueceu-senha`
- `GET /redefinir-senha`
- `POST /redefinir-senha`
- `GET /logout`

### Reservas

- `GET /reserva/criar/{chacara_id}`
- `POST /reserva/criar/{chacara_id}`
- `GET /reserva/confirmacao/{id}`

### Cliente

- `GET /cliente`
- `GET /cliente/historico`
- `GET /cliente/reserva/{id}`
- `GET /cliente/meus-dados`
- `POST /cliente/meus-dados`

### Proprietário

- `GET /proprietario`
- `GET /proprietario/dashboard`
- `GET /proprietario/dados-cadastrais`
- `POST /proprietario/dados-cadastrais`
- `GET /proprietario/chacaras`
- `GET /proprietario/chacaras/criar`
- `POST /proprietario/chacaras/criar`
- `GET /proprietario/chacaras/editar/{id}`
- `POST /proprietario/chacaras/editar/{id}`
- `GET /proprietario/chacaras/excluir/{id}`
- `POST /proprietario/chacaras/excluir/{id}`
- `GET /proprietario/chacaras/fotos/{id}`
- `POST /proprietario/chacaras/fotos/{id}`
- `GET /proprietario/disponibilidade`
- `GET /proprietario/disponibilidade/{chacara_id}`
- `POST /proprietario/disponibilidade/salvar`
- `GET /proprietario/faturamento`

### Admin

- `GET /admin`
- `GET /admin/dashboard`
- `GET /admin/proprietarios`
- `GET /admin/proprietarios/{id}`
- `POST /admin/proprietarios/{id}/status`
- `GET /admin/usuarios`
- `GET /admin/usuarios/{id}`
- `POST /admin/usuarios/{id}/status`

### Integrações e diagnóstico

- `POST /webhook_asaas.php`
- `GET /teste-conexao`, somente em desenvolvimento

## 16. Observações de segurança

- Nunca salve senhas em texto puro.
- Use `password_hash` para gravar senhas.
- Use `password_verify` para validar login.
- Mantenha credenciais em variáveis de ambiente.
- Não versionar chaves reais do Google Maps ou Asaas.
- Use HTTPS em produção.
- Restrinja a chave do Google Maps por domínio/IP.
- Use ambiente Sandbox do Asaas para testes.
- Valide webhooks antes de confiar em eventos de pagamento.
- Formulários sensíveis devem usar token CSRF.
- Saídas HTML devem ser escapadas com helper apropriado.
- Consultas ao banco devem usar `prepare` e `execute`.
- Rotas internas devem validar o perfil do usuário autenticado.
- Cliente não deve acessar reserva de outro cliente.
- Proprietário não deve editar chácara de outro proprietário.
- Uploads devem aceitar apenas tipos permitidos e não executar scripts.
- Em produção, erros técnicos não devem ser exibidos ao usuário final.
- Troque a senha inicial do administrador após o primeiro login.

## 17. Próximas fases recomendadas

1. Criar testes automatizados para models, controllers e fluxos críticos.
2. Revisar o encoding dos arquivos e padronizar tudo em UTF-8.
3. Adicionar validação de assinatura/autenticidade dos webhooks do Asaas.
4. Melhorar logs estruturados para reservas, pagamentos e erros.
5. Criar rotina de conciliação de pagamentos com o Asaas.
6. Implementar recuperação de senha com envio real de e-mail.
7. Criar auditoria para ações administrativas.
8. Adicionar paginação e filtros avançados nos painéis.
9. Melhorar política de upload, compressão e remoção de imagens antigas.
10. Criar política de backup e restore do PostgreSQL.
11. Preparar deploy com servidor web dedicado, HTTPS e variáveis de ambiente.
12. Criar pipeline de lint, testes e validação antes de produção.
13. Definir e testar uma Content-Security-Policy compatível com mapas, fontes, imagens e integrações externas.
