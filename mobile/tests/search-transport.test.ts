import { createServer } from 'node:http';
import { once } from 'node:events';
import type { AddressInfo } from 'node:net';
import { URL as NodeURL, URLSearchParams as NodeSearchParams } from 'node:url';
import { URLSearchParams as ExpoSearchParams } from 'whatwg-url-minimum';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { catalog } from '../src/api/catalog';

// Characterization: the actual catalog client sends real HTTP to an isolated
// loopback receiver. No database, production endpoint or app mock is involved.
const requests: NodeURL[] = [];
const server = createServer((request, response) => {
  requests.push(new NodeURL(request.url!, 'http://localhost'));
  response.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  response.end(JSON.stringify({ data: [], meta: { request_id: 'search-test', pagination: { page: 2, per_page: 12, total: 0, total_pages: 0 } } }));
});

beforeAll(async () => {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  vi.stubEnv('EXPO_PUBLIC_API_URL', `http://127.0.0.1:${(server.address() as AddressInfo).port}/api/v1`);
});
afterAll(async () => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
});

for (const [runtime, implementation] of [['Node/Web', NodeSearchParams], ['Expo polyfill', ExpoSearchParams]] as const) {
  describe(runtime, () => {
    it.each(['Ibiúna', 'Atibaia', 'São Paulo', 'São Bernardo do Campo', ' São Paulo ', 'São  Paulo', 'São Paulo + região & campo', 'DestinoInexistente', ''])('preserva cidade %j no receptor HTTP, tipo e paginação', async city => {
      vi.stubGlobal('URLSearchParams', implementation);
      try {
        await catalog.list(city, 2, 'sitio');
        const received = requests.at(-1)!;
        expect(received.pathname).toBe('/api/v1/imoveis');
        expect(received.searchParams.get('cidade')).toBe(city.trim() || null);
        expect(received.searchParams.get('page')).toBe('2');
        expect(received.searchParams.get('per_page')).toBe('12');
        expect(received.searchParams.get('tipo_imovel')).toBe('sitio');
        expect([...received.searchParams.keys()].sort()).toEqual((city.trim() ? ['cidade', 'page', 'per_page', 'tipo_imovel'] : ['page', 'per_page', 'tipo_imovel']).sort());
      } finally {
        vi.unstubAllGlobals();
      }
    });
  });
}
