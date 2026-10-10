import { defineConfig } from '@playwright/test';
export default defineConfig({
  outputDir: './test-results/application-admin', testDir: './tests', testMatch: '**/application-admin.spec.ts', workers: 1,
  use: { browserName: 'chromium', channel: process.platform === 'win32' ? 'msedge' : undefined },
  webServer: { command: 'npm run dev:application-admin -- --host localhost --port 5185', url: 'http://localhost:5185/admin/', reuseExistingServer: false,
    env: { VITE_ENTRA_TENANT_ID: '11111111-1111-1111-1111-111111111111', VITE_ENTRA_SPA_CLIENT_ID: '22222222-2222-2222-2222-222222222222', VITE_ENTRA_API_CLIENT_ID: '33333333-3333-3333-3333-333333333333' } },
});
