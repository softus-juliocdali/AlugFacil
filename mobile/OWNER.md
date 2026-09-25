# Proprietário no mesmo app (0.3.1)

O catálogo continua nativo. A navegação do proprietário é nativa e abre os fluxos
web responsivos dentro de uma WebView privada no iOS/Android. Não há cópia das
regras comerciais nem banco separado. Na prévia web, o navegador continua no
site por POST; isso não simula a WebView nativa.

## Telas conectadas

- Dashboard, imóveis, cadastro/edição, fotos e preferência individual de entrada.
- Calendário: bloquear datas é diferente de pausar novas reservas do anúncio.
- Reservas dos imóveis e detalhes próprios, faturamento e histórico.
- Mensalidades, obrigações, condições comerciais e pagamento PIX transparente.
- Recebimentos e dados cadastrais, autorização financeira e estado do vínculo.
- Minhas reservas como hóspede e criação de reserva pelo catálogo existente.

Os formulários usam as rotas, CSRF, serviços, modelos e permissões do web. Não
foram alterados aprovação, comissões, repasses ou cronogramas. `entrada_bps` e
`aceita_parcelamento` continuam na configuração comercial de cada imóvel.

## Endpoints adicionados

- `POST /api/v1/mobile/web-session`: Bearer, destino permitido, ticket de 60 s.
- `POST /mobile/entrar`: consome ticket uma única vez; não aceita destino livre.
- `GET /mobile/mensalidades/{id}`: consulta a obrigação do proprietário e PIX.
- `POST /mobile/mensalidades/{id}`: CSRF, emite/reutiliza PIX pelo serviço existente.

As demais ações reutilizam as rotas web existentes. O login/refresh móvel passa
a admitir clientes e proprietários, nunca administradores/afiliados. Tokens não
vão para URL, JavaScript da página ou armazenamento da WebView. O cookie integrado
fica vinculado à sessão móvel; logout, bloqueio, expiração ou troca de senha
retiram seu acesso. Tickets temporários são privados em `storage/cache/mobile-bridge`.

## Limitações e operação

- A integração exige internet e o backend publicado. Favoritos permanece na
  situação anterior do aplicativo; não foi incluído nesta entrega.
- Mensalidade no app tem PIX transparente. Cartão de mensalidade ainda depende
  da captura hospedada legada e **não está implementado de forma transparente**.
  O app não abre essa fatura nem cria outra cobrança quando já existe cartão.
- Cobranças legadas sem obrigação vinculada precisam de conciliação pelo suporte.
- O modo Sandbox e sua lista de operações permitidas não foram ampliados: emitir
  uma mensalidade exige que o ambiente financeiro autorize essa operação. Testes
  não habilitam cobrança real, aprovação Asaas, repasse ou transferência.
- A reserva como hóspede segue a elegibilidade real dos imóveis e a configuração
  dos meios de pagamento. O checkout do cliente de outro imóvel nunca é liberado
  ao proprietário. Documentos de boleto podem abrir externamente; faturas não.

## Validação e builds

`npm run typecheck`, `npm run lint`, `npm test` e `npx expo export --platform all`.
Backend: `php tests/mobile_owner_integration_test.php`,
`php tests/mobile_auth_postgresql_test.php`, `php tests/owner_web_payment_test.php`.
Os testes de integração recusam banco diferente de PostgreSQL local de desenvolvimento.
Gateway mensal é simulado, incluindo reabertura e autorização; nenhuma cobrança real.

Android: `eas build -p android --profile preview` (APK interno).
iOS: `eas build -p ios --profile preview-ios` (simulador, sem assinatura de loja).
Os mesmos package/bundle IDs usam `com.alugfacil.cliente`; projeto EAS existente.
Build interno/simulador não significa publicação na App Store ou Google Play.
