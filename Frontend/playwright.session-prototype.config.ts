import { defineConfig } from '@playwright/test';
import { runtimeDirectory } from './tests/helpers/sessionPrototypeSetup';

export default defineConfig({
  testDir: './tests', testMatch: 'session-security-prototype.spec.ts', workers: 1,
  fullyParallel: false, timeout: 25000,
  globalSetup: './tests/helpers/sessionPrototypeSetup.ts',
  globalTeardown: './tests/helpers/sessionPrototypeTeardown.ts',
  outputDir: './test-results/session-prototype',
  reporter: [['list'], ['json', { outputFile: './test-results/session-prototype-report.json' }]],
  use: { serviceWorkers: 'block', trace: 'off' },
  projects: [
    { name: 'edge', use: { browserName: 'chromium', channel: 'msedge' } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
  webServer: [
    { command: 'npx vite --config vite.session-prototype.config.ts', url: 'http://127.0.0.1:5196/prototypes/session-security/index.html', reuseExistingServer: false },
    { command: 'php -S 127.0.0.1:5197 -t ../prototypes/session-security ../prototypes/session-security/router.php', url: 'http://127.0.0.1:5197/__session-prototype/health', reuseExistingServer: false, env: { SESSION_PROTOTYPE_ENABLED: 'local-synthetic-only', SESSION_PROTOTYPE_RUNTIME: runtimeDirectory } },
  ],
});
