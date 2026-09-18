import { defineConfig } from '@playwright/test';

const env = {
  VITE_API_BASE_URL: 'http://localhost:8080/api/v1',
  VITE_PUBLIC_URL: 'http://localhost:5183/', VITE_PORTAL_URL: 'http://localhost:5184/',
  VITE_ENTRA_TENANT_ID: '11111111-1111-1111-1111-111111111111',
  VITE_ENTRA_SPA_CLIENT_ID: '22222222-2222-2222-2222-222222222222',
  VITE_ENTRA_API_CLIENT_ID: '33333333-3333-3333-3333-333333333333',
  VITE_ENTRA_REDIRECT_URI: 'http://localhost:5184/',
  VITE_CUSTOMER_ENTRA_TENANT_ID: '44444444-4444-4444-4444-444444444444',
  VITE_CUSTOMER_ENTRA_SUBDOMAIN: 'testcustomers',
  VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID: '55555555-5555-5555-5555-555555555555',
  VITE_CUSTOMER_ENTRA_API_CLIENT_ID: '66666666-6666-6666-6666-666666666666',
  VITE_GOOGLE_MAPS_BROWSER_API_KEY: '',
};
export default defineConfig({
  testDir: './tests', testMatch: ['**/portal.spec.ts', '**/customer.spec.ts'], fullyParallel: true, workers: 2,
  outputDir: './test-results/browser',
  use: { browserName: 'chromium', channel: process.env.PLAYWRIGHT_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined), trace: 'retain-on-failure' },
  webServer: [
    { command: 'npm run dev -- --host localhost --port 5183', url: 'http://localhost:5183', env, reuseExistingServer: false },
    { command: 'npm run dev:portal -- --host localhost --port 5184', url: 'http://localhost:5184', env, reuseExistingServer: false },
  ],
});
