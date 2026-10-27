import { defineConfig } from '@playwright/test';
import base from './playwright.invitations.config';

export default defineConfig({
  ...base, testMatch: '**/practitioner-applications.spec.ts',
  outputDir: './test-results/practitioner-applications',
  webServer: (Array.isArray(base.webServer) ? base.webServer : []).map(server => ({ ...server, env: {
    ...server.env, VITE_STAFF_INVITATIONS_ENABLED: 'false',
    VITE_PRACTITIONER_APPLICATIONS_ENABLED: 'true', VITE_STAFF_EXTERNAL_AUTH_ENABLED: 'true',
  } })),
});
