# AlugFácil — APK Android de homologação

## Situação atual após publicação da API

API pública aprovada em https://alugfacil.net.br/api/v1 em 21/09/2026;
health/home/imoveis 200 JSON. Catálogo real sem imóveis elegíveis; nenhum dado
criado para homologação. Deploy/backup/regressão documentados em
../docs/PRE-DEPLOY-API-PUBLICA-2026-09-21.md. Esta seção substitui o histórico abaixo.

Preview agora usa EXPO_PUBLIC_API_URL=https://alugfacil.net.br/api/v1 e
EXPO_NO_DOTENV=1. Owner softus-digital; projectId
a584a50c-0d44-44a4-adb6-51dd89b00e4e; slug alugfacil-mobile.
Package com.alugfacil.cliente; versão 0.1.0/versionCode 1.

Doctor 21/21; typecheck/lint aprovados; mobile 34 aprovados e 4 ignorados
(testes opt-in de fixtures LAN, não aplicáveis à produção). Export Android e
export:embed aprovados após limpar cache Metro que continha o bundle LAN antigo.
Bundle novo contém HTTPS oficial, sem 192.168.* ou 127.0.0.1. Literais localhost
restantes pertencem ao parser WHATWG e helper Devtools do SDK; não são API ou
dependência de rede local do app em release. Nenhuma URL local em app/ ou src/.
Manifesto preview com usesCleartextTraffic=false e sem networkSecurityConfig LAN.
Upload EAS inspecionado: 38 arquivos mobile, sem .env, segredos, PHP, keystore,
fixtures, exports, caches ou android gerado. Configurações LAN existem apenas
no perfil development e plugin condicional de desenvolvimento, não no runtime
do Preview. Ambiente remoto EAS preview sem variáveis adicionais.

## APK concluído e inspecionado

- Build ID: `5c9b1556-559d-4ee3-8308-6aaa29cb411e`.
- Status final: **FINISHED**; Android, preview, distribuição interna.
- [Página oficial do build](https://expo.dev/accounts/softus-digital/projects/alugfacil-mobile/builds/5c9b1556-559d-4ee3-8308-6aaa29cb411e).
- [Download oficial do APK](https://expo.dev/artifacts/eas/AAJfdKjaCpq56WhczU2kCEc6O9UydhniiddKZjUeOeI.apk).
- Tamanho: 113.613.821 bytes (aproximadamente 113,6 MB / 108,35 MiB).
- SHA-256: `b6262137c636670acfa41d1bbb4957e53c4af6ce9c24a82e70daa5ab81ff5d95`.
- Concluído: 21/09/2026 10:47:53 America/Sao_Paulo. Expiração informada pelo
  EAS: 05/10/2026 10:21:25 America/Sao_Paulo; guardar o APK baixado.
- Credenciais Android gerenciadas pelo EAS; nenhum segredo exportado no relatório.
- Nenhum submit/Play Store, commit ou push realizado.

O APK oficial foi baixado e inspecionado: manifesto com package
com.alugfacil.cliente, versionName 0.1.0, versionCode 1 e cleartext false.
Permissões finais: INTERNET, VIBRATE, ACCESS_NETWORK_STATE e permissão interna
DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION. Sem microfone, armazenamento externo
ou sobreposição. Nenhum .env/keystore/chave privada encontrado nas entradas ZIP.
Bundle dentro do APK contém https://alugfacil.net.br/api/v1, sem 192.168.* ou
127.0.0.1. O literal localhost interno do SDK descrito acima permanece; não foi
alegada ausência literal desse texto no binário.

Para instalar: abrir a página/download oficial no Android, baixar o APK,
autorizar o navegador a instalar quando solicitado, instalar e abrir AlugFácil.
Expo Go e backend local não são necessários. É necessária conexão à internet.

Limitações: catálogo público atualmente vazio; autenticação mobile, Favoritos,
Reservas e Perfil permanecem na experiência de visitante; botão Reservar aponta
para a tela informativa de acesso. Não houve teste físico deste APK nesta tarefa.
Detalhe/disponibilidade de imóvel elegível aguardam catálogo real elegível.
Homologar no aparelho abertura/splash, Home, imagens, busca, tabs e reabertura;
quando houver imóvel real elegível, também detalhe e botão Reservar.

Arquivos desta continuação: mobile/app.json (projectId real), mobile/eas.json
(HTTPS preview), mobile/EAS-ANDROID.md e docs/PRE-DEPLOY-API-PUBLICA-2026-09-21.md.
Os ajustes anteriores de app.config.js, assets, plugin, package/lock e .easignore
foram mantidos. Arquivos PHP locais não foram sobrescritos pela variante da VPS.
Staging, logs sanitizados, bundle e APK estão em storage/tmp, ignorado pelo Git.

Git final: master, HEAD 5edff980e1d3ba0282daf8f40835488966f09642, sem commit/push.
O diff rastreado continua nos dois PHP previamente alterados, 32 inserções e
10 remoções; mobile e novos arquivos permanecem não rastreados:

```text
 M app/models/Chacara.php
 M public/index.php
?? .easignore
?? app/api/
?? docs/API-PUBLICA-ETAPA-2-2026-09-18.md
?? docs/AUDITORIA-MOBILE-2026-09-18.md
?? docs/DEBUG-BUSCA-MOBILE-2026-09-18.md
?? docs/DIAGNOSTICO-BUSCA-MOBILE-2026-09-18.md
?? docs/HOMOLOGACAO-MOBILE-DADOS-LOCAIS-2026-09-18.md
?? docs/MOBILE-ETAPA-3-2026-09-18.md
?? docs/PRE-DEPLOY-API-PUBLICA-2026-09-21.md
?? docs/openapi.yaml
?? mobile/
?? tests/mobile_homologation_http_test.php
?? tests/mobile_homologation_visual_test.cjs
?? tests/public_api_city_search_test.php
?? tests/public_api_contract_test.cjs
?? tests/public_api_http_test.php
?? tests/public_api_postgresql_test.php
?? tests/public_api_unit_test.php
?? tests/support/PublicApiTest.php
?? tests/support/mobile_homologation_environment.php
?? tests/support/seed_mobile_homologation.php
```

## Mudança de plano: homologação externa

**Esta seção substitui o plano LAN histórico abaixo.** Owner escolhido:
`softus-digital`. O APK para o cliente deverá usar exclusivamente
`https://alugfacil.net.br/api/v1`, depois da publicação e validação da API.
Preview não contém mais URL LAN e desabilita leitura de `.env`; sua URL ficou
propositadamente ausente até a API pública ser aprovada. A configuração rejeita
preview com qualquer outra URL. Development continua local.

Nova consulta HTTPS: health, home e imoveis retornaram 404 HTML; Home Web e login
retornaram 200 HTML. Nenhum build foi enviado. A sessão SSH manual foi observada
no terminal, mas a ferramenta disponível só lê esse terminal. SSH BatchMode
para root@2.24.213.77:22 retornou Permission denied (publickey,password).
Ainda falta acesso remoto operável para comparar revisão/schema, fazer backup
e publicar. Nenhuma alteração remota, migration ou operação Asaas realizada.

Auditoria pré-deploy: ../docs/PRE-DEPLOY-API-PUBLICA-2026-09-21.md.

## Estado em 21/09/2026

Preparação local realizada. Build EAS **ainda não enviado**: falta escolher o
proprietário entre as contas autenticadas `softus-digital` e `juliocdlima`.
Não há projectId, build ID, APK ou URL de build confirmados nesta etapa.

## Ambiente e identidade

- Expo 57.0.24; React Native 0.86.3; Expo CLI 57.0.26.
- Node 24.17.0; npm 11.13.0; EAS CLI 21.0.2.
- Nome: AlugFácil; slug: alugfacil-mobile; scheme: alugfacil.
- Android package: com.alugfacil.cliente (não havia identificador anterior).
- Versão: 0.1.0; versionCode: 1; versionamento local, sem incremento automático.
- Retrato; logo oficial reutilizada em ícone 1024 × 1024, adaptive icon e splash.
- Microfone, armazenamento externo e sobreposição bloqueados no manifesto.
  Manifesto principal mantém INTERNET e VIBRATE; o manifesto final do APK ainda
  precisa ser conferido após o build.

## API

`EXPO_PUBLIC_API_URL=http://192.168.15.6:8000/api/v1`

Classificação: **local/LAN**. O endereço antigo no `.env` local é `.2`, mas o
IPv4 Wi-Fi observado nesta tarefa é `.6`. O `.env` existente foi preservado.
Os perfis development e preview fornecem a URL atual explicitamente.
PC/backend e banco local precisam continuar ligados, com celular na mesma LAN
e acesso à porta 8000. O APK não oferecerá acesso ao catálogo fora dessa rede.
Mudança do IP exige ajustar os perfis e a restrição HTTP e gerar outro APK.

`https://alugfacil.net.br/api/v1/health` retornou HTTP 404 com HTML.
Não foi constatada API pública funcional no domínio conhecido; nenhum deploy
foi realizado. Homologação externa depende de publicar a API separadamente.

O cliente HTTP continua lendo exclusivamente EXPO_PUBLIC_API_URL. Não foram
introduzidos tokens ou segredos. HTTP é permitido somente para 192.168.15.6,
nos perfis development/preview, por network-security-config Android.
Demais destinos mantêm cleartext desabilitado. Production exige API HTTPS
configurada no ambiente EAS production e não usa o `.env` local.

Para iniciar o backend local com os endereços corretos das imagens:

```powershell
cd 'C:\PROJETOS\Alug Facil\sistema\mobile'
$env:EXPO_PUBLIC_API_URL = 'http://192.168.15.6:8000/api/v1'
npm run backend
```

O script existente verifica que o banco é o local alugfacil_dev; não executa
migrations nem seeds. Foi iniciado durante esta tarefa.

## Perfis

- development: APK interno com bundle embarcado, sem expo-dev-client/Metro.
  Para desenvolvimento interativo continua disponível `npm start` com Expo Go.
- preview: APK interno instalável, sem Expo Go.
- production: futuro AAB, condicionado à API HTTPS oficial; não executado.

## Validações realizadas

- Expo Doctor: 21/21 checks aprovados.
- TypeScript: aprovado (`npm run typecheck`).
- ESLint: aprovado (`npm run lint`, zero warnings).
- Vitest: 38/38 testes aprovados, 4 arquivos, zero falhas/ignorados, com
  `MOBILE_SEARCH_HTTP=1` e a URL LAN atual.
- Health, Home, listagem e detalhe: HTTP 200; catálogo com nove imóveis.
- Hero, logo e imagem de imóvel: HTTP 200, tipos image/png ou image/jpeg.
- Prebuild Android: aprovado; manifesto e XML de política de rede inspecionados.
- Export Android/Hermes: aprovado, 1.539 módulos, 33 assets, bundle de 3,7 MB.
  Typecheck e lint repetidos após o export também aprovados.
- Proteções de production: rejeitam API ausente, HTTP local, credenciais na URL
  e domínio de exemplo.
- EAS build:inspect, stage archive: 38 arquivos mobile conferidos. Inclui fontes
  locais não commitadas. Exclui .env, backend PHP, banco, credenciais, node_modules,
  android gerado, exports, caches e logs. A lista é controlada por `.easignore`
  na raiz Git; novos arquivos de configuração essenciais precisam ser incluídos.
- A primeira tentativa de integração falhou porque `.2` não era mais o IP do PC;
  foi repetida com `.6` e todos os testes passaram, sem alteração nos testes.
- Instalação pontual: expo-splash-screen ~57.0.9. npm reporta 14 alertas moderados;
  não foi aplicado npm audit fix nem upgrade amplo.
- Prebuild avisa que userInterfaceStyle depende de expo-system-ui, ausente desde
  a configuração anterior. Tema deve ser conferido no teste físico do APK.

## Próximo passo EAS

Após escolher o owner real, vincular/criar o projeto por `eas init` e registrar
o projectId retornado em app.json. Não inventar identificadores. Reinspecionar
o upload e verificar variáveis do ambiente EAS preview antes do envio.

```powershell
eas whoami
eas init
eas build --platform android --profile preview
```

Usar credenciais Android gerenciadas pelo EAS; parar se houver interação humana
necessária. Registrar build ID, página expo.dev, status Finished e artefato .apk.
Não executar submit, production, publicação iOS ou deploy do backend.

## Instalação e homologação física (pendentes)

Quando o build terminar, abrir sua página oficial expo.dev no Android, baixar o
APK, permitir instalação pelo navegador quando solicitado, instalar e abrir
AlugFácil. Expo Go não é necessário.

Testar separadamente do Expo Go: abertura, logo/splash, Home, API, imagens,
busca, detalhes, tabs, Favoritos, Reservas, Perfil, botão Reservar e reabertura.
As funcionalidades dessas telas continuam no estágio existente do aplicativo.

## Git e arquivos desta tarefa

Branch master; HEAD 5edff980e1d3ba0282daf8f40835488966f09642.
Sem commit, push, reset, clean, stash ou rebase. Modificações anteriores em
app/models/Chacara.php e public/index.php preservadas (32 inserções/10 remoções).
Pasta mobile e arquivos locais de API/docs/testes já eram não rastreados.

Arquivos criados/alterados nesta tarefa:

- .easignore (raiz do repositório)
- mobile/app.json
- mobile/app.config.js
- mobile/eas.json
- mobile/plugins/with-local-network.js
- mobile/assets/icon-android.png
- mobile/assets/adaptive-icon.png
- mobile/package.json
- mobile/package-lock.json
- mobile/EAS-ANDROID.md

Saídas locais ignoradas: mobile/android, mobile/dist-native/eas-preview e
storage/tmp/eas-preview-audit-*. Não fazem parte do upload ou versionamento.
