import { test, expect } from '@playwright/test';

test('Paginação preserva cards ao carregar mais; página reduzida só no transporte de teste', async ({ page, request }) => {
  const response = await request.get('/api/v1/imoveis?per_page=2');
  expect(response.status()).toBe(200);
  const total = (await response.json()).meta.pagination.total as number;
  expect(total).toBeGreaterThan(2);
  expect(total).toBeLessThan(100);
  const pages: string[] = [];
  await page.route('**/api/v1/imoveis?*', async (route) => {
    const url = new URL(route.request().url());
    pages.push(url.searchParams.get('page') ?? '');
    // The real PHP API and local DB still produce every record. No synthetic listing.
    url.searchParams.set('per_page', '2');
    const response = await route.fetch({ url: url.toString() });
    await route.fulfill({ response });
  });
  await page.goto('/buscar');
  await expect(page.getByRole('link', { name: /^Ver / })).toHaveCount(2);
  for (let count = 2; count < total; count += 2) {
    await page.getByRole('button', { name: 'Carregar mais lugares', exact: true }).click();
    await expect(page.getByRole('link', { name: /^Ver / })).toHaveCount(Math.min(count + 2, total));
  }
  await expect(page.getByRole('button', { name: 'Carregar mais lugares', exact: true })).toHaveCount(0);
  expect(pages).toEqual(Array.from({ length: Math.ceil(total / 2) }, (_, i) => String(i + 1)));
});
