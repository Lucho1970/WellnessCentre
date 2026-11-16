import { chromium } from '@playwright/test';

const browser = await chromium.launch({ channel: 'msedge', headless: true });
const cases = [
  ['portal', 'http://localhost:18080/'],
  ['practitioner entry', 'http://localhost:18080/staff/external'],
  ['Wellnexa', 'http://localhost:18081/'],
  ['central Admin', 'http://localhost:18082/admin/'],
  ['public site', 'http://localhost:18083/'],
];
let failed = 0;
try {
  for (const [name, url] of cases) {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.name));
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.waitForFunction(() => document.body?.innerText.trim().length > 100, { timeout: 12000 });
      const body = (await page.locator('body').innerText()).trim();
      const pass = response?.status() === 200
        && errors.length === 0
        && !body.includes('Unable to start the application')
        && !body.includes('Unable to start administration');
      console.log(`${name}: status=${response?.status()} rendered_chars=${body.length} page_errors=${errors.length} ${pass ? 'PASS' : 'FAIL'}`);
      if (!pass) failed++;
    } catch (error) {
      console.log(`${name}: ${error.name} FAIL`);
      failed++;
    } finally {
      await page.close();
    }
  }
} finally {
  await browser.close();
}
process.exitCode = failed ? 1 : 0;
