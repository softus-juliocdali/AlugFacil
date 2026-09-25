import { test, expect } from '@playwright/test';

// These IDs identify the existing local homologation catalog, never app content.
const cases = [
  ['Ibiúna', 666], ['Atibaia', 667], ['São Paulo', 668], ['São Bernardo do Campo', 175],
] as const;

for (const route of ['/', '/buscar']) {
  for (const [city, expectedId] of cases) {
    test(`${route}: input ${city} chega completo a API real`, async ({ page }) => {
      await page.goto(route);
      const input = page.getByRole('textbox', { name: 'Cidade ou destino' });
      await input.fill(city);
      const pending = page.waitForResponse(response => {
        const url = new URL(response.url());
        return url.pathname.endsWith('/api/v1/imoveis') && url.searchParams.get('cidade') === city;
      });
      await input.press('Enter');
      const response = await pending;
      const request = new URL(response.url());
      expect(response.status()).toBe(200);
      expect(request.searchParams.get('cidade')).toBe(city);
      expect(request.searchParams.get('page')).toBe('1');
      expect(request.searchParams.get('per_page')).toBe('12');
      const body = await response.json();
      expect(body.data.map((property: { id: number }) => property.id)).toContain(expectedId);
      const property = body.data.find((property: { id: number }) => property.id === expectedId);
      await expect(page.getByRole('link', { name: 'Ver ' + property.nome })).toBeVisible();
      await expect(page.getByRole('textbox', { name: 'Cidade ou destino' })).toHaveValue(city);
    });
  }
}

test('Buscar preserva tipo ao submeter cidade composta e permite resultado vazio', async ({ page }) => {
  await page.goto('/buscar?tipo=area_lazer');
  const input = page.getByRole('textbox', { name: 'Cidade ou destino' });
  await input.fill('São Paulo');
  const pending = page.waitForResponse(response => new URL(response.url()).searchParams.get('cidade') === 'São Paulo');
  await input.press('Enter');
  const response = await pending;
  expect(new URL(response.url()).searchParams.get('tipo_imovel')).toBe('area_lazer');
  expect((await response.json()).data.map((property: { id: number }) => property.id)).toContain(668);
  await page.getByRole('textbox', { name: 'Cidade ou destino' }).fill('DestinoInexistenteBuscaMobile');
  await page.getByRole('textbox', { name: 'Cidade ou destino' }).press('Enter');
  await expect(page.getByText('Nenhum lugar por aqui ainda', { exact: true })).toBeVisible();
});
