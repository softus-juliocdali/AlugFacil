import { test, expect } from '@playwright/test';
for (const [city, id] of [['Ibiúna',666],['Atibaia',667],['São Paulo',668],['São Bernardo do Campo',175]] as const) {
  test(`busca real: ${city}`, async ({ page }) => {
    await page.goto('/');
    const response = page.waitForResponse(r => r.url().includes('/api/v1/imoveis?') && new URL(r.url()).searchParams.get('cidade') === city);
    await page.getByRole('textbox', { name: 'Cidade ou destino' }).fill(city);
    await page.getByRole('textbox', { name: 'Cidade ou destino' }).press('Enter');
    const result = await response;
    expect(result.status()).toBe(200);
    const body = await result.json();
    expect(body.data.map((item: {id: number}) => item.id)).toContain(id);
    await expect(page.getByText(`${body.meta.pagination.total} lugares encontrados`, { exact: true })).toBeVisible();
    await expect(page.getByRole('link').and(page.locator(`a[href="/imovel/${id}"]`))).toBeVisible();
  });
}
