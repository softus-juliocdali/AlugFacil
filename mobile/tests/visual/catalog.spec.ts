import { test, expect, type Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const evidence = resolve('../storage/screenshots/mobile-etapa3');
async function shot(page: Page, name: string) {
  await mkdir(evidence, { recursive: true });
  await page.waitForTimeout(250); // Allow the intentional image fade to finish before visual QA.
  await page.screenshot({ path: resolve(evidence, name + '.png') });
}
test('API real: Home, cards, busca, detalhe e navegação de visitante', async ({ page, request }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await request.get('/api/v1/home?per_page=6');
  expect(response.status()).toBe(200);
  const home = await response.json();
  expect(home.data.imoveis.length).toBeGreaterThan(0);
  const property = home.data.imoveis[0];
  await page.goto('/');
  await expect(page.getByText(home.data.hero.titulo, { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: /^Ver / }).first()).toBeAttached();
  await page.evaluate(() => document.fonts.ready);
  await shot(page, '01-home-real');
  await page.getByRole('link', { name: /^Ver / }).first().scrollIntoViewIfNeeded();
  await shot(page, '02-cards-reais');
  await page.getByRole('link', { name: /^Ver / }).first().click();
  await expect(page.getByText('DIÁRIA BASE', { exact: true })).toBeVisible();
  await shot(page, '03-detalhe-real');
  await page.getByRole('button', { name: 'Reservar', exact: true }).scrollIntoViewIfNeeded();
  await shot(page, '04-detalhe-reservar');
  await page.getByRole('button', { name: 'Reservar', exact: true }).click();
  await expect(page.getByText('Bom ter você de volta', { exact: true })).toBeVisible();
  await shot(page, '05-login');
  await page.getByRole('button', { name: 'Continuar explorando' }).click();
  await page.getByRole('textbox', { name: 'Cidade ou destino' }).fill(property.cidade);
  const search = page.waitForResponse((r) => r.url().includes('/api/v1/imoveis?') && new URL(r.url()).searchParams.get('cidade') === property.cidade);
  await page.getByRole('textbox', { name: 'Cidade ou destino' }).press('Enter');
  expect((await search).status()).toBe(200);
  await expect(page.getByText(/lugares encontrados/)).toBeVisible();
  await shot(page, '06-busca-real');
  await page.setViewportSize({ width: 320, height: 740 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await shot(page, '07-busca-320');
  await page.setViewportSize({ width: 390, height: 844 });
  for (const [route, name] of [['/favoritos', '08-favoritos'], ['/reservas', '09-reservas'], ['/perfil', '10-perfil']]) {
    await page.goto(route!);
    await expect(page.getByText(route === '/perfil' ? 'Navegando como visitante' : 'Bom ter você de volta', { exact: true })).toBeVisible();
    await shot(page, name!);
  }
  expect(errors).toEqual([]);
});

test('API real: busca vazia e detalhe inexistente', async ({ page }) => {
  await page.goto('/buscar?cidade=DestinoInexistenteValidacaoMobile');
  await expect(page.getByText('Nenhum lugar por aqui ainda', { exact: true })).toBeVisible();
  await shot(page, '11-vazio-real');
  await page.goto('/imovel/2147483647');
  await expect(page.getByText('Este imóvel não está disponível.', { exact: true })).toBeVisible();
  await shot(page, '12-detalhe-404-real');
});

test('Falha de rede simulada, nova tentativa e loading com resposta real adiada', async ({ page }) => {
  await page.route('**/api/v1/home?*', (route) => route.abort('failed'));
  await page.goto('/');
  await expect(page.getByText('Não conseguimos carregar', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Tentar novamente', exact: true }).scrollIntoViewIfNeeded();
  await shot(page, '13-erro-rede-simulado');
  await page.unroute('**/api/v1/home?*');
  await page.route('**/api/v1/home?*', async (route) => {
    const response = await route.fetch();
    await new Promise((done) => setTimeout(done, 1500));
    await route.fulfill({ response });
  });
  await page.getByRole('button', { name: 'Tentar novamente', exact: true }).click();
  await expect(page.getByRole('progressbar', { name: 'Buscando seu próximo destino…', exact: true })).toBeVisible();
  await shot(page, '14-loading-resposta-adiada');
  await expect(page.getByRole('link', { name: /^Ver / }).first()).toBeAttached();
});

test('Imagem remota indisponível tem fallback explícito', async ({ page }) => {
  await page.route('**/assets/img/chacara-*', (route) => route.abort());
  await page.route('**/assets/uploads/chacaras/*', (route) => route.abort());
  await page.goto('/');
  await expect(page.getByText('Imagem indisponível', { exact: true }).first()).toBeAttached();
  await page.getByText('Imagem indisponível', { exact: true }).first().scrollIntoViewIfNeeded();
  await shot(page, '15-imagem-fallback');
});
