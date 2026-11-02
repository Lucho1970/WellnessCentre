import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { existsSync, lstatSync, mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { resolve, relative, join } from 'node:path';

export const runtimeDirectory = resolve(process.cwd(), '../.tmp/session-security-prototype');
function verifiedRuntime() {
  const workspaceTemporary = resolve(process.cwd(), '../.tmp');
  if (relative(workspaceTemporary, runtimeDirectory) !== 'session-security-prototype') throw new Error('Unsafe prototype runtime directory.');
  if (existsSync(runtimeDirectory) && lstatSync(runtimeDirectory).isSymbolicLink()) throw new Error('Prototype runtime must not be a symbolic link.');
  return runtimeDirectory;
}
export default function setup() {
  const runtime = verifiedRuntime(); mkdirSync(runtime, { recursive: true });
  const keys = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
  writeFileSync(join(runtime, 'private.pem'), keys.privateKey, { mode: 0o600 });
  writeFileSync(join(runtime, 'public.pem'), keys.publicKey, { mode: 0o600 });
  writeFileSync(join(runtime, 'control-secret'), randomBytes(32).toString('hex'), { mode: 0o600 });
  writeFileSync(join(runtime, 'state.json'), JSON.stringify({ offset: 0, sessions: {}, challenges: {}, events: [] }), { mode: 0o600 });
}
export function teardown() {
  const runtime = verifiedRuntime();
  // Named disposable keys only; no recursive cleanup or unrelated file deletion.
  for (const name of ['private.pem', 'control-secret']) {
    const target = join(runtime, name);
    if (existsSync(target)) unlinkSync(target);
  }
}
