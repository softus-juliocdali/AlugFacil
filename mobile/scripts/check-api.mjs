import { loadEnvFile } from 'node:process';
try { loadEnvFile('.env'); } catch { /* allow explicit environment */ }
const base = process.env.EXPO_PUBLIC_API_URL?.replace(/\/$/, '');
if (!base) throw new Error('Configure EXPO_PUBLIC_API_URL.');
let firstId;
for (const path of ['/health', '/home?per_page=6', '/imoveis?per_page=2']) {
  const response = await fetch(base + path, { credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(15000) });
  if (!response.ok || !response.headers.get('content-type')?.includes('application/json') || response.headers.has('set-cookie')) throw new Error(`${path}: HTTP ${response.status}, resposta inválida.`);
  const body = await response.json();
  if (!body.data || !body.meta?.request_id) throw new Error('Envelope inválido.');
  if (path.startsWith('/imoveis')) firstId = body.data[0]?.id;
  console.log(`[OK] GET ${path}: HTTP ${response.status}${body.meta.pagination ? `, total=${body.meta.pagination.total}` : ''}`);
}
if (firstId) {
  const response = await fetch(`${base}/imoveis/${firstId}`, { credentials: 'omit', redirect: 'error' });
  const body = await response.json();
  if (!response.ok || !Number.isSafeInteger(body.data?.preco?.diaria_centavos)) throw new Error('Detalhe inválido.');
  console.log('[OK] GET /imoveis/{id}: HTTP 200, centavos inteiros.');
} else console.log('[NÃO EXECUTADO] Detalhe real: catálogo local vazio. Testar com fixture isolada.');
