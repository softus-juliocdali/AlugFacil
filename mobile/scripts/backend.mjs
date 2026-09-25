import { loadEnvFile } from 'node:process';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const mobile = resolve(dirname(fileURLToPath(import.meta.url)), '..');
try { loadEnvFile(resolve(mobile, '.env')); } catch { /* explicit environment also supported */ }
const api = new URL(process.env.EXPO_PUBLIC_API_URL ?? '');
if (api.protocol !== 'http:' || api.pathname !== '/api/v1' || api.username || api.password || api.search || api.hash) throw new Error('Use uma URL HTTP da rede local terminada em /api/v1 no mobile/.env.');
const guard = spawnSync('php', [resolve(mobile, 'scripts/local-backend-check.php')], { cwd: resolve(mobile, '..'), stdio: 'inherit' });
if (guard.status !== 0) process.exit(1);
const child = spawn('php', ['-S', `0.0.0.0:${api.port || 80}`, '-t', 'public', 'public/router.php'], { cwd: resolve(mobile, '..'), stdio: 'inherit', env: { ...process.env, APP_ENV: 'development', APP_URL: api.origin } });
console.log(`Backend local: ${api.href}/health`);
child.on('error', () => { console.error('Não foi possível iniciar PHP. Confira o PATH.'); process.exitCode = 1; });
child.on('exit', (code) => { process.exitCode = code ?? 0; });
process.on('SIGINT', () => child.kill());
process.on('SIGTERM', () => child.kill());
