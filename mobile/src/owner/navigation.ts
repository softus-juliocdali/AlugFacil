export const ownerSections = [
  { title: 'Dashboard e reservas dos imóveis', path: '/proprietario/dashboard' },
  { title: 'Meus imóveis e fotos', path: '/proprietario/chacaras' },
  { title: 'Cadastrar imóvel', path: '/proprietario/chacaras/criar' },
  { title: 'Calendário e bloqueio de datas', path: '/proprietario/disponibilidade' },
  { title: 'Faturamento', path: '/proprietario/faturamento' },
  { title: 'Mensalidades e pagamentos', path: '/proprietario/mensalidades' },
  { title: 'Recebimentos e vínculo financeiro', path: '/proprietario/recebimentos' },
  { title: 'Dados cadastrais', path: '/proprietario/dados-cadastrais' },
] as const;

export function allowedDestination(path: string, role?: string): boolean {
  return path === '/cliente/historico' || /^\/reserva\/criar\/[1-9]\d*$/.test(path)
    || role === 'proprietario' && ownerSections.some(item => item.path === path);
}

export function allowedNavigation(url: string, origin: string): boolean {
  try { const parsed = new URL(url); return parsed.origin === origin && !parsed.username && !parsed.password && !/^\/(admin|afiliado)(\/|$)/.test(parsed.pathname); }
  catch { return false; }
}
