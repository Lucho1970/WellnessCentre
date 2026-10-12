import { defineConfig } from '@playwright/test';
import base from './playwright.config';

export default defineConfig({
  ...base, testMatch: '**/staff-invitations.spec.ts', workers: 1,
  outputDir: './test-results/staff-invitations',
  webServer: (Array.isArray(base.webServer) ? base.webServer : []).map(server => ({ ...server, env: {
    ...server.env, VITE_STAFF_INVITATIONS_ENABLED: 'true',
    VITE_STAFF_EXTERNAL_TENANT_ID: '44444444-4444-4444-4444-444444444444',
    VITE_STAFF_EXTERNAL_SUBDOMAIN: 'teststaff',
    VITE_STAFF_EXTERNAL_SPA_CLIENT_ID: '77777777-7777-7777-7777-777777777777',
    VITE_STAFF_EXTERNAL_API_CLIENT_ID: '88888888-8888-8888-8888-888888888888',
  } })),
});
