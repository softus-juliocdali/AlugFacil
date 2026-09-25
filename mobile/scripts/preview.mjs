import { Buffer } from 'node:buffer';
// Local-only Web QA server. Same-origin proxy avoids changing CORS in the PHP API.
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';
import { loadEnvFile } from 'node:process';
try { loadEnvFile('.env'); } catch { /* allow explicit environment */ }
const root = resolve('dist');
const api = process.env.EXPO_PUBLIC_API_URL;
if (!api) throw new Error('Configure EXPO_PUBLIC_API_URL.');
const mime = { '.html': 'text/html', '.js': 'application/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.ttf': 'font/ttf', '.woff2': 'font/woff2', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://preview.invalid');
    if (url.pathname.startsWith('/api/v1/')) {
      const authPost = request.method === 'POST' && /^\/api\/v1\/auth\/(login|cadastro|refresh|logout)$/.test(url.pathname);
      if (request.method !== 'GET' && !authPost) { response.writeHead(405); response.end(); return; }
      const headers = { Accept: 'application/json' };
      if (request.headers.authorization) headers.Authorization = request.headers.authorization;
      let body;
      if (authPost) {
        headers['Content-Type'] = 'application/json';
        const chunks = []; let length = 0;
        for await (const chunk of request) { length += chunk.length; if (length > 16384) { response.writeHead(413); response.end(); return; } chunks.push(chunk); }
        body = Buffer.concat(chunks);
      }
      const upstream = await fetch(api.replace(/\/$/, '') + url.pathname.slice(7) + url.search, { method: authPost ? 'POST' : 'GET', body, headers, credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(15000) });
      response.writeHead(upstream.status, { 'Content-Type': upstream.headers.get('content-type') ?? 'application/json', 'Cache-Control': 'no-store' });
      response.end(Buffer.from(await upstream.arrayBuffer())); return;
    }
    let file = resolve(root, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(root + sep) && file !== root) { response.writeHead(403); response.end(); return; }
    try { if (!(await stat(file)).isFile()) file = resolve(root, 'index.html'); } catch { file = resolve(root, 'index.html'); }
    response.writeHead(200, { 'Content-Type': mime[extname(file)] ?? 'application/octet-stream' });
    response.end(await readFile(file));
  } catch { response.writeHead(502, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ error: { code: 'PREVIEW_UPSTREAM', message: 'Backend local indisponível.', fields: {} }, request_id: 'preview' })); }
}).listen(8082, '127.0.0.1', () => console.log('Prévia Web em loopback na porta 8082; API encaminhada ao backend configurado.'));
