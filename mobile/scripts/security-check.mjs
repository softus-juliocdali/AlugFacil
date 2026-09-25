// Local static check. Never print values from the backend environment.
import { readFile, readdir } from 'node:fs/promises';
import { parseEnv } from 'node:util';
import { resolve } from 'node:path';
import { Buffer } from 'node:buffer';

const env = parseEnv(await readFile('../.env', 'utf8'));
const privateValues = Object.entries(env).filter(([key, value]) => /PASS|TOKEN|SECRET|API_KEY|CPF|CNPJ/i.test(key) && value.length >= 8).map(([, value]) => Buffer.from(value));
const omitted = new Set(['node_modules', '.expo', '.git', 'test-results', 'playwright-report']);
let scanned = 0;
const failures = [];
async function scan(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (omitted.has(entry.name)) continue;
    const path = resolve(dir, entry.name);
    if (entry.isDirectory()) { await scan(path); continue; }
    const bytes = await readFile(path);
    scanned++;
    if (privateValues.some((value) => bytes.includes(value))) failures.push(path);
  }
}
await scan('.');
if (failures.length) throw new Error(`Revisar ${failures.length} arquivo(s): possível valor privado detectado. Valores omitidos.`);
console.log(`[OK] ${scanned} arquivos locais, incluindo bundles: nenhum valor privado conhecido do .env do backend encontrado.`);
console.log('Verificação estática por correspondência; não substitui auditoria de segurança.');
