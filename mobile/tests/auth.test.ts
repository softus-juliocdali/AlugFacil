import { describe, expect, it, vi } from 'vitest';
import { createHttpClient } from '../src/api/http';
import { SessionManager } from '../src/auth/session-manager';
import { meSchema } from '../src/auth/schemas';
import { safeIntent } from '../src/auth/intent';

const user = { id: 10, nome: 'Cliente Teste', email: 'mobile@teste.invalid', telefone: null, tipo_usuario: 'cliente' };
const pair = (generation: number) => ({ user, access_token: String(generation).repeat(64), refresh_token: String(generation + 1).repeat(64), token_type: 'Bearer', expires_in: 900, access_expires_at: '2026-09-21T12:00:00Z', refresh_expires_at: '2026-10-21T12:00:00Z' });
const json = (data: unknown, status = 200) => new Response(JSON.stringify(status < 400 ? { data, meta: { request_id: 'test' } } : { error: { code: 'UNAUTHENTICATED', message: 'Entre novamente.', fields: {} }, request_id: 'test' }), { status, headers: { 'Content-Type': 'application/json' } });
function setup(saved: string | null = null) {
  let token = saved;
  const storage = { get: vi.fn(async () => token), set: vi.fn(async (value: string) => { token = value; }), remove: vi.fn(async () => { token = null; }) };
  const transport = vi.fn(async (url: string, _init?: RequestInit): Promise<Response> => json(url.endsWith('/me') ? user : url.endsWith('/logout') ? { logged_out: true } : pair(1)));
  const clear = vi.fn();
  const manager = new SessionManager(storage, createHttpClient(() => 'https://api.example.test/api/v1', transport), clear);
  return { manager, storage, transport, clear, saved: () => token };
}
describe('mobile session boundary', () => {
  it('Home has no blocking session and guest restoration makes no HTTP request', async () => {
    const s = setup(); expect(s.manager.snapshot().user).toBeNull();
    await s.manager.restore(); expect(s.manager.snapshot().restoring).toBe(false); expect(s.transport).not.toHaveBeenCalled();
  });
  it.each(['login', 'cadastro'] as const)('%s persists only refresh, loads real me and clears private cache', async kind => {
    const s = setup(); await s.manager.signIn(kind, { email: user.email, senha: 'senha-local' });
    expect(s.saved()).toBe(pair(1).refresh_token); expect(s.storage.set).toHaveBeenCalledWith(pair(1).refresh_token);
    expect(s.manager.snapshot().user).toEqual(user); expect(s.clear).toHaveBeenCalled();
    expect(s.transport.mock.calls[0]?.[1]?.credentials).toBe('omit');
    expect(new Headers(s.transport.mock.calls[0]?.[1]?.headers).has('Authorization')).toBe(false);
  });
  it('restores with refresh then me', async () => {
    const s = setup('a'.repeat(64)); await s.manager.restore();
    expect(s.transport.mock.calls.map(c => new URL(c[0]).pathname)).toEqual(['/api/v1/auth/refresh', '/api/v1/me']);
    expect(s.manager.snapshot().user).toEqual(user);
  });
  it('offline restoration retains refresh and permits retry', async () => {
    const s = setup('a'.repeat(64)); s.transport.mockRejectedValueOnce(new Error('offline'));
    await s.manager.restore(); expect(s.saved()).toBe('a'.repeat(64)); expect(s.manager.snapshot().error).toBeTruthy();
    await s.manager.restore(); expect(s.manager.snapshot().user).toEqual(user);
  });
  it('definitive rejection clears saved session', async () => {
    const s = setup('a'.repeat(64)); s.transport.mockResolvedValueOnce(json(null, 401));
    await s.manager.restore(); expect(s.saved()).toBeNull(); expect(s.clear).toHaveBeenCalled();
  });
  it('concurrent 401 responses rotate only once and retry each once', async () => {
    const s = setup(); await s.manager.signIn('login', {});
    let refreshes = 0;
    s.transport.mockImplementation(async (url, init) => {
      if (url.endsWith('/refresh')) { refreshes++; await new Promise(resolve => setTimeout(resolve, 15)); return json(pair(3)); }
      const auth = new Headers(init?.headers).get('Authorization');
      return auth === `Bearer ${pair(1).access_token}` ? json(null, 401) : json(user);
    });
    const result = await Promise.all([s.manager.privateRequest('/me', meSchema), s.manager.privateRequest('/me', meSchema)]);
    expect(refreshes).toBe(1); expect(result.map(r => r.data.id)).toEqual([10, 10]);
  });
  it('logout revokes remotely, clears memory, storage and private cache', async () => {
    const s = setup(); await s.manager.signIn('login', {}); await s.manager.logout();
    expect(s.saved()).toBeNull(); expect(s.manager.snapshot().user).toBeNull(); expect(s.clear).toHaveBeenCalledTimes(2);
    expect(s.transport.mock.calls.at(-1)?.[0]).toContain('/auth/logout');
  });
  it('offline logout reports failure and retains credential for revocation retry', async () => {
    const s = setup(); await s.manager.signIn('login', {}); s.transport.mockRejectedValueOnce(new Error('offline'));
    await expect(s.manager.logout()).rejects.toThrow(); expect(s.saved()).not.toBeNull();
    await s.manager.logout(); expect(s.saved()).toBeNull();
  });
  it('return intent allows only supported internal routes', () => {
    for (const value of ['https://evil.test', '//evil.test', '/admin', '/imovel/1?redirect=evil', '/imovel/../admin', ['perfil']]) expect(safeIntent(value)).toBe('/perfil');
    for (const value of ['/favoritos', '/reservas', '/imovel/175']) expect(safeIntent(value)).toBe(value);
  });
  it('foreground restoration cannot overwrite an explicit login in flight', async () => {
    const s = setup('a'.repeat(64));
    let finish!: (value: Response) => void;
    s.transport.mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }));
    const login = s.manager.signIn('login', { email: user.email, senha: 'local' });
    await vi.waitFor(() => expect(s.transport).toHaveBeenCalledTimes(1));
    await s.manager.restore();
    await expect(s.manager.signIn('login', {})).rejects.toMatchObject({ code: 'AUTH_IN_PROGRESS' });
    expect(s.storage.get).not.toHaveBeenCalled();
    finish(json(pair(1))); await login;
    expect(s.manager.snapshot().user).toEqual(user);
    expect(s.transport.mock.calls.some(c => c[0].endsWith('/refresh'))).toBe(false);
  });
  it('failed explicit login cannot leave restoration pending forever', async () => {
    const s = setup();
    s.transport.mockResolvedValueOnce(json(null, 401));
    await expect(s.manager.signIn('login', {})).rejects.toMatchObject({ status: 401 });
    expect(s.manager.snapshot().restoring).toBe(false);
  });
});
