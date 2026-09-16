import { defineConfig } from '@playwright/test';
import { readFileSync } from 'node:fs';

function buildBase(surface: string) {
  const html = readFileSync(`dist/${surface}/index.html`, 'utf8');
  const src = html.match(/src="([^"]+\/assets\/[^\"]+)"/)?.[1] ?? html.match(/src="(\/assets\/[^\"]+)"/)?.[1];
  if (!src) throw new Error(`Cannot find ${surface} build asset path`);
  return src.slice(0, src.indexOf('/assets/') + 1);
}
const publicBase = buildBase('public'), portalBase = buildBase('portal');
export default defineConfig({
  testDir: './tests', testMatch: '**/build.smoke.spec.ts', workers: 1,
  outputDir: './test-results/build',
  metadata: { publicBase, portalBase },
  use: { browserName: 'chromium', channel: process.env.PLAYWRIGHT_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined) },
  webServer: [
    { command: 'npm run preview:public -- --host localhost --port 5193', url: `http://localhost:5193${publicBase}`, reuseExistingServer: false },
    { command: 'npm run preview:portal -- --host localhost --port 5194', url: `http://localhost:5194${portalBase}`, reuseExistingServer: false },
  ],
});
