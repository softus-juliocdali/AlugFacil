import { describe, expect, it, vi } from 'vitest';
import { z } from 'zod';
import { createHttpClient, apiBase, type HttpTransport } from '../src/api/http';
import { listingPath } from '../src/api/catalog';
import { money, hours } from '../src/utils/format';
import { propertySchema } from '../src/api/schemas';

const origin = 'https://catalogo.example.test/api/v1';
const schema = z.object({ data: z.string() });
describe('Contrato público e transporte', () => {
  it('normaliza a origem e rejeita configuração ausente, credenciais e caminho incorreto', () => {
    expect(apiBase(origin + '/')).toBe(origin);
    for (const value of [undefined, '', 'file:///tmp/api/v1', 'https://user:pass@example.test/api/v1', 'https://example.test/login', origin + '?token=x']) expect(() => apiBase(value)).toThrow();
  });
  it('envia GET sem cookies, sem CSRF, com cancelamento e JSON', async () => {
    const transport = vi.fn<HttpTransport>().mockResolvedValue(new Response('{"data":"ok"}', { headers: { 'Content-Type': 'application/json' } }));
    const client = createHttpClient(() => origin, transport);
    expect(await client('/home', schema)).toEqual({ data: 'ok' });
    const options = transport.mock.calls[0]?.[1];
    expect(options).toMatchObject({ credentials: 'omit', redirect: 'error', method: 'GET' });
    expect(new Headers(options?.headers).get('Accept')).toBe('application/json');
    expect(new Headers(options?.headers).has('Cookie')).toBe(false);
    expect(new Headers(options?.headers).has('X-CSRF-Token')).toBe(false);
    expect(options?.signal).toBeInstanceOf(AbortSignal);
  });
  it.each([404, 422, 500])('trata HTTP %s sem exibir payload interno', async (status) => {
    const transport = vi.fn<HttpTransport>().mockImplementation(async () => new Response('{"error":{"message":"INTERNAL_TEST_MARKER"}}', { status, headers: { 'Content-Type': 'application/json' } }));
    const client = createHttpClient(() => origin, transport);
    await expect(client('/home', schema)).rejects.toMatchObject({ status });
    try { await client('/home', schema); } catch (error) { expect(String(error)).not.toContain('INTERNAL_TEST_MARKER'); }
  });
  it('rejeita HTML e JSON com contrato incompatível', async () => {
    for (const response of [new Response('<html>login</html>', { headers: { 'Content-Type': 'text/html' } }), new Response('{"different":true}', { headers: { 'Content-Type': 'application/json' } })]) {
      const client = createHttpClient(() => origin, vi.fn<HttpTransport>().mockResolvedValue(response));
      await expect(client('/home', schema)).rejects.toMatchObject({ code: 'INVALID_RESPONSE' });
    }
  });
  it('trata perda de conexão sem mostrar detalhes técnicos', async () => {
    const client = createHttpClient(() => origin, vi.fn<HttpTransport>().mockRejectedValue(new TypeError('internal network data')));
    await expect(client('/home', schema)).rejects.toMatchObject({ code: 'NETWORK_ERROR', status: 0 });
  });
  it('encaminha aborto da query', async () => {
    const controller = new AbortController();
    const client = createHttpClient(() => origin, vi.fn<HttpTransport>().mockImplementation(async (_url, options) => new Promise((_resolve, reject) => options?.signal?.addEventListener('abort', () => reject(new DOMException('Canceled', 'AbortError'))))));
    const pending = client('/home', schema, controller.signal);
    controller.abort();
    await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
  });
  it('encerra requisições que excedem 15 segundos', async () => {
    vi.useFakeTimers();
    try {
      const client = createHttpClient(() => origin, vi.fn<HttpTransport>().mockImplementation(async (_url, options) => new Promise((_resolve, reject) => options?.signal?.addEventListener('abort', () => reject(new Error('abort'))))));
      const assertion = expect(client('/home', schema)).rejects.toMatchObject({ code: 'NETWORK_ERROR' });
      await vi.advanceTimersByTimeAsync(15000);
      await assertion;
    } finally { vi.useRealTimers(); }
  });
  it('codifica cidade e filtros sem concatenar entrada como query', () => {
    const params = new URLSearchParams(listingPath(' São Paulo & região ', 2, 'sitio').split('?')[1]);
    expect(params.get('cidade')).toBe('São Paulo & região');
    expect(params.get('page')).toBe('2');
    expect(params.get('tipo_imovel')).toBe('sitio');
  });
});
describe('Apresentação de dados', () => {
  it('formata centavos sem calcular valor financeiro', () => {
    expect(money(123456).replace(/\s/g, ' ')).toBe('R$ 1.234,56');
    expect(money(1).replace(/\s/g, ' ')).toBe('R$ 0,01');
    expect(() => money(1.5)).toThrow();
    expect(() => money(-1)).toThrow();
  });
  it('representa horários ausentes sem inventar valores', () => {
    expect(hours(null, null)).toBe('Não informado');
    expect(hours('10:00', null)).toBe('A partir de 10:00');
    expect(hours(null, '18:00')).toBe('Até 18:00');
  });
  it('rejeita preço fracionário e remove campos desconhecidos', () => {
    const property = { id: 1, nome: 'Fixture isolada', tipo_imovel: 'chacara', cidade: 'Cidade de teste', regiao: null, preco: { diaria_centavos: 12345, moeda: 'BRL', unidade: 'diaria' }, imagem: { url: 'https://example.test/photo.jpg', ilustrativa: true }, avaliacoes: { media: 0, quantidade: 0 }, internal_marker: 'test' };
    expect(propertySchema.parse(property)).not.toHaveProperty('internal_marker');
    expect(propertySchema.safeParse({ ...property, preco: { ...property.preco, diaria_centavos: 12.34 } }).success).toBe(false);
  });
});
