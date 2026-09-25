# AlugFácil mobile — catálogo e autenticação

Expo SDK 57 + React Native + TypeScript + Expo Router + TanStack Query.
Abertura pública, com login/cadastro de cliente opcionais e sessão nativa no SecureStore.
Não cria reservas ou pagamentos. A etapa 4 e suas validações estão em
[MOBILE-ETAPA-4](../docs/MOBILE-ETAPA-4-2026-09-21.md); APK Preview em [EAS-ANDROID.md](EAS-ANDROID.md).
Preview usa exclusivamente `https://alugfacil.net.br/api/v1`.

## Executar no celular

Requisitos: Node.js LTS, PHP no PATH, PostgreSQL **local** configurado pelo `.env`
da raiz, computador e celular na mesma rede Wi-Fi e Expo Go compatível com SDK 57.
Não executar migrations nem seeds para iniciar o app.

Na primeira instalação:

```powershell
cd 'C:\PROJETOS\Alug Facil\sistema\mobile'
npm ci
```

Configure `mobile/.env` a partir de `.env.example`. Use o IPv4 Wi-Fi mostrado por
`ipconfig`, com a porta 8000 e o sufixo `/api/v1`. O `.env` real já foi criado para
a máquina desta homologação e é ignorado pelo Git. O IP pode mudar com o DHCP.
Não coloque senha, token ou chave em variáveis EXPO_PUBLIC: elas entram no bundle.

Terminal 1 — backend:

```powershell
cd 'C:\PROJETOS\Alug Facil\sistema\mobile'
npm run backend
```

O comando verifica `APP_ENV`, `DB_HOST` e `DB_NAME`, permitindo somente a base
local `alugfacil_dev`. Inicia o router PHP existente em `0.0.0.0:8000` e define
APP_URL **somente no processo filho** com a origem configurada no mobile/.env.
Não altera o `.env` da raiz. HTTP privado é permitido pela API somente em
development/test; produção continua exigindo HTTPS.

Terminal 2 — mobile:

```powershell
cd 'C:\PROJETOS\Alug Facil\sistema\mobile'
npm start
```

No celular:

1. Instale/atualize o [Expo Go para SDK 57](https://expo.dev/go).
2. Conecte celular e computador à mesma rede Wi-Fi, sem isolamento entre clientes.
3. Abra no navegador do celular a URL de `EXPO_PUBLIC_API_URL` seguida de `/health`.
   Ela deve mostrar JSON. Se não abrir, verifique endereço, backend e permissão do
   PHP no Firewall do Windows para rede privada. Metro usa a porta 8081.
4. No Android, leia o QR code pelo Expo Go; no iPhone, pela Câmera.
5. O aplicativo abre na Home pública. Busque uma cidade e toque em um imóvel.
6. Faça a homologação de teclado, voltar, Safe Area e arrastar para atualizar.

O [Expo informa que o Expo Go atual no iOS requer a mesma conta Expo na CLI e no aplicativo](https://expo.dev/changelog/expo-go-57-login).
Se solicitado, execute `npx expo login` no computador e entre no Expo Go. Essa é
a conta da ferramenta de desenvolvimento; a Home do **AlugFácil continua pública**.
Login AlugFácil é separado da conta Expo. O APK Preview dispensa Expo Go.

## Comandos de verificação

```powershell
npm run typecheck
npm run lint
npm test
npm run check:api
npx expo install --check
npx expo-doctor
node scripts/security-check.mjs
```

`check:api` faz apenas GET na origem configurada. `security-check` procura valores
privados conhecidos do `.env` do backend nos arquivos mobile e bundles locais,
sem imprimir esses valores; é uma verificação estática, não uma auditoria completa.

## Prévia Web para QA

A API não habilita CORS. `npm run web` sozinho não resolve o acesso entre origens.
Use a prévia com proxy local abaixo; ela não muda as regras do backend:

```powershell
# Backend já deve estar aberto em outro terminal.
$apiOriginal = $env:EXPO_PUBLIC_API_URL
# A prévia com autenticação aceita conexões apenas deste computador.
$env:EXPO_PUBLIC_API_URL = 'http://127.0.0.1:8082/api/v1'
npx expo export --platform web --clear
if ($null -eq $apiOriginal) { Remove-Item Env:EXPO_PUBLIC_API_URL } else { $env:EXPO_PUBLIC_API_URL = $apiOriginal }
npm run preview
```

Abra `http://127.0.0.1:8082`. O servidor encaminha GET e POST de autenticação de
`/api/v1/*` ao backend configurado em `.env`. O bundle Web de QA usa essa mesma
origem. Para os testes visuais, em outro terminal:

```powershell
$env:MOBILE_PREVIEW_URL = 'http://127.0.0.1:8082'
npx playwright test
```

Os testes usam Microsoft Edge instalado e um catálogo local com pelo menos seis
imóveis. Não criam registros. A paginação reduz `per_page` apenas no transporte do
teste, e as respostas continuam vindo da API PHP. Falha de rede, atraso e falha de
imagem são simulados exclusivamente no navegador de teste. Capturas ficam em
`../storage/screenshots/mobile-etapa3`, ignorado pelo Git.

## Organização e limites

- `app/`: rotas, Stack e cinco abas; `/` é a Home.
- `src/api/`: transporte HTTP, schemas e catálogo público sem Bearer.
- `src/providers/`: cache público e atualização ao voltar ao app.
- `src/components/`: cards, imagem com fallback, busca, botões e estados.
- `src/theme/`: cores, espaçamentos, raios, fontes e assets.
- `src/screens/`: Home, busca, detalhe, login/cadastro e perfil/estados por sessão.
- `src/auth/`: sessão, SecureStore, coordenação de refresh e intenções internas validadas.

O cliente usa GET, `credentials: omit`, timeout de 15 segundos, cancelamento e
validação dos JSONs. O cache fica somente em memória. Preço é diária base em centavos;
o app apenas formata em BRL. O detalhe não apresenta capacidade, comodidades,
descontos ou condições de contratação ausentes do contrato.

Entrar, Criar conta e Reservar abrem um aviso da próxima versão. Favoritos e
Reservas não possuem listas fictícias. A disponibilidade ainda não é consumida.

Veja o relatório A–L e a matriz de aceite em
`../docs/MOBILE-ETAPA-3-2026-09-18.md`. A homologação física permanece pendente.
