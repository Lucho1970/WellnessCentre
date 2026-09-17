import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';

test('public artifacts contain no staff authentication or private feature modules', () => {
  const files = readdirSync('dist/public/assets').filter(file => file.endsWith('.js'));
  const code = files.map(file => readFileSync(`dist/public/assets/${file}`, 'utf8')).join('\n');
  expect(code).not.toContain('login.microsoftonline.com');
  expect(code).not.toContain('StaffAuthProvider');
  expect(code).not.toContain('/auth/me');
  expect(code).not.toContain('ciamlogin.com');
  expect(readFileSync('dist/public/.htaccess', 'utf8')).toContain('^api(?:/|$)');
  expect(readFileSync('dist/portal/.htaccess', 'utf8')).toContain('index.html');
});

test('built public and portal deep links load independently, including base paths', async ({ page }, info) => {
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: { name: 'Build Smoke Clinic', email: 'clinic@example.test', phone: null, legal_name: null } } }));
  await page.goto(`http://localhost:5193${info.config.metadata.publicBase}contact`);
  await expect(page.getByRole('heading', { name: 'Contact Build Smoke Clinic' })).toBeVisible();
  await page.reload(); await expect(page.getByRole('heading', { name: 'Contact Build Smoke Clinic' })).toBeVisible();
  const portalRequests: string[] = [];
  page.on('request', request => { if (request.url().includes('/api/v1/')) portalRequests.push(request.url()); });
  await page.goto(`http://localhost:5194${info.config.metadata.portalBase}admin/clients`);
  await expect(page.getByRole('heading', { name: 'Staff portal', exact: true })).toBeVisible();
  await page.reload(); await expect(page.getByRole('button', { name: 'Sign in with Microsoft', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add client' })).toHaveCount(0);
  expect(portalRequests.length).toBeGreaterThan(0);
  expect(portalRequests.every(url => url.startsWith('http://localhost:5194/api/v1/'))).toBe(true);
  expect(errors).toEqual([]);
});

test('built client callback loads independently of staff login', async ({ page }, info) => {
  await page.route('**/api/v1/site-config', route => route.fulfill({ json: { data: { name: 'Build Smoke Clinic' } } }));
  await page.goto(`http://localhost:5194${info.config.metadata.portalBase}client/auth/callback`);
  await expect(page.getByRole('heading', { name: 'Client portal', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Staff portal', exact: true })).toHaveCount(0);
});
