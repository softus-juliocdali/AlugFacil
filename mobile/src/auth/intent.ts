export function safeIntent(value: unknown): '/perfil' | '/favoritos' | '/reservas' | '/proprietario' | `/imovel/${number}` {
  if (value === '/proprietario') return value;
  if (value === '/favoritos' || value === '/reservas' || value === '/perfil') return value;
  if (typeof value === 'string' && /^\/imovel\/[1-9]\d{0,9}$/.test(value) && Number(value.split('/')[2]) <= 2147483647) return value as `/imovel/${number}`;
  return '/perfil';
}
