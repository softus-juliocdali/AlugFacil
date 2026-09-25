import { describe, it, expect } from 'vitest';
import { allowedDestination, allowedNavigation, ownerSections } from '../src/owner/navigation';
import { z } from 'zod';
import { createHttpClient } from '../src/api/http';
import { userSchema } from '../src/auth/schemas';
describe('Owner integration boundary', () => {
  it('admits the original owner role without granting it to clients', () => {
    const user={id:12,nome:'Teste',email:'owner@example.test',telefone:null,tipo_usuario:'proprietario'};
    expect(userSchema.parse(user).tipo_usuario).toBe('proprietario');
    expect(userSchema.safeParse({...user,tipo_usuario:'admin'}).success).toBe(false);
    for(const section of ownerSections){expect(allowedDestination(section.path,'proprietario')).toBe(true);expect(allowedDestination(section.path,'cliente')).toBe(false);}
  });
  it('preserves guest access without exposing private checkout as a bridge destination', () => {
    for(const role of ['cliente','proprietario']){expect(allowedDestination('/cliente/historico',role)).toBe(true);expect(allowedDestination('/reserva/criar/5',role)).toBe(true);}
    for(const path of ['/reserva/confirmacao/2','//evil.test','/admin','/proprietario/../admin','/proprietario/dashboard?x=1'])expect(allowedDestination(path,'proprietario')).toBe(false);
  });
  it('never navigates a WebView to a hosted invoice or another origin', () => {
    const origin='https://alugfacil.net.br';
    expect(allowedNavigation(origin+'/proprietario/reservas/2',origin)).toBe(true);
    for(const url of ['https://sandbox.asaas.com/i/charge','https://alugfacil.net.br.evil.test','javascript:alert(1)',origin+'/admin/dashboard','https://user@alugfacil.net.br'])expect(allowedNavigation(url,origin)).toBe(false);
  });
});


describe('Owner bridge HTTP transport', () => {
  it('sends the hyphenated bridge route as authenticated JSON POST', async () => {
    let called = false;
    const http = createHttpClient(() => 'https://alugfacil.net.br/api/v1', async (url, init) => {
      called = true;
      expect(url).toBe('https://alugfacil.net.br/api/v1/mobile/web-session');
      expect(init?.method).toBe('POST');
      expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer test-only');
      expect(JSON.parse(String(init?.body))).toEqual({ path: '/proprietario/dashboard' });
      return new Response(JSON.stringify({ ok: true }), { headers: { 'Content-Type': 'application/json' } });
    });
    await expect(http('/mobile/web-session', z.object({ ok: z.boolean() }), undefined, { Authorization: 'Bearer test-only' }, { path: '/proprietario/dashboard' })).resolves.toEqual({ ok: true });
    expect(called).toBe(true);
    await expect(http('/../admin', z.unknown())).rejects.toThrow('Rota inválida');
  });
});
