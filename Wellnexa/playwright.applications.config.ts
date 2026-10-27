import { defineConfig } from '@playwright/test'
import base from './playwright.config'
export default defineConfig({
  ...base, testDir: './tests/applications', outputDir: './test-results/applications',
  webServer: { ...base.webServer as object, command: 'npm run dev -- --port 5177', url: 'http://localhost:5177', reuseExistingServer: false,
    env: { VITE_PRACTITIONER_APPLICATIONS_ENABLED: 'true' } },
})
