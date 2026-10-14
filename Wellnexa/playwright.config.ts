import { defineConfig } from '@playwright/test'
export default defineConfig({
  testDir: './tests/browser', workers: 1,
  outputDir: './test-results',
  use: { browserName: 'chromium', channel: process.platform === 'win32' ? 'msedge' : undefined },
  webServer: { command: 'npm run dev -- --port 5177', url: 'http://localhost:5177', reuseExistingServer: false },
})
