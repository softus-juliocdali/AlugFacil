# Autenticação mobile — etapa 4

AuthProvider restaura a sessão em segundo plano; a Home continua pública. SessionManager
mantém o access token somente em memória e salva apenas o refresh no Expo SecureStore.
A prévia Web usa memória, sem persistência insegura em localStorage.

Requisições privadas passam por manager.privateRequest: Bearer, um único refresh em voo,
e no máximo uma repetição da chamada original após 401. O catálogo não envia Bearer.
Cookies e CSRF Web não são reutilizados. Dados privados usam chave ['private', userId, ...]
ou meta.private=true para cancelamento e limpeza ao trocar de identidade ou sair.

Refresh rejeitado definitivamente apaga a sessão. Falha de rede conserva a credencial.
Logout offline informa falha e conserva a credencial para repetir a revogação quando online.
Se a resposta de uma rotação se perder, repetir o token consumido revoga a família no
servidor; será necessário entrar novamente. Não há repetição automática do POST de refresh.

Login/cadastro aceitam somente intenções internas validadas em intent.ts. Perfil usa /me.
Favoritos, reservas e checkout continuam estados informativos, sem operações reais.
