import { loadEnvFile } from 'node:process';
import { beforeAll, describe, expect, it } from 'vitest';
import { catalog } from '../src/api/catalog';

// Opt-in: invokes the exact queryFn dependency against the existing LOCAL API.
describe.skipIf(process.env.MOBILE_SEARCH_HTTP !== '1')('catalog.list → API local real', () => {
  beforeAll(() => {
    loadEnvFile('.env');
    const url = new URL(process.env.EXPO_PUBLIC_API_URL!);
    if (!/^(127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)$/.test(url.hostname)) throw new Error('Teste restrito a API em IP local.');
  });
  it.each([['Ibiúna',666],['Atibaia',667],['São Paulo',668],['São Bernardo do Campo',175]] as const)('%s retorna o imovel %i pelo cliente do app', async (city, id) => {
    const response = await catalog.list(city, 1, '');
    expect(response.data.map(property => property.id)).toContain(id);
    expect(response.meta.pagination.total).toBeGreaterThan(0);
  });
});
