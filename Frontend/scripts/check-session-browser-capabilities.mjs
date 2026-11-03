import { createServer } from 'node:http';
import { chromium, firefox, webkit } from '@playwright/test';

// Independent synthetic probe: no application, identity provider, token or real profile.
const browsers = { edge: [chromium, { channel: 'msedge' }], firefox: [firefox, {}], webkit: [webkit, {}] };
const selected = process.argv.slice(2);
if (selected.some(name => !Object.hasOwn(browsers, name))) throw new Error('Expected edge, firefox or webkit.');
const results = [];
const server = createServer((request, response) => {
  response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store', ...(request.url === '/set' ? { 'Set-Cookie': 'synthetic_http_cookie=fixed-synthetic; Path=/; HttpOnly; SameSite=Lax' } : {}) });
  const sent = (request.headers.cookie ?? '').split(';').some(cookie => cookie.trim().startsWith('synthetic_http_cookie='));
  response.end(`<!doctype html><title>Synthetic browser capability probe</title><p id="result">${sent ? 'sent' : 'withheld'}</p>`);
});
await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
const address = server.address();
if (!address || typeof address === 'string') throw new Error('Loopback probe unavailable.');
const crossSiteServer = createServer((_request, response) => {
  response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' });
  response.end(`<!doctype html><form method="post" action="http://127.0.0.1:${address.port}/check"><button type="submit">Probe synthetic POST</button></form>`);
});
await new Promise((resolve, reject) => { crossSiteServer.once('error', reject); crossSiteServer.listen(0, '127.0.0.2', resolve); }).catch(async error => {
  await new Promise((resolve, reject) => { server.close(closeError => closeError ? reject(closeError) : resolve()); });
  throw error;
});
const crossSiteAddress = crossSiteServer.address();
if (!crossSiteAddress || typeof crossSiteAddress === 'string') throw new Error('Cross-site loopback probe unavailable.');
const metadata = cookies => cookies.map(({ name, domain, path, sameSite, secure, httpOnly }) => ({ name, domain, path, sameSite, secure, httpOnly }));
try {
  for (const name of selected.length ? selected : Object.keys(browsers)) {
    const [type, options] = browsers[name];
    let browser;
    try {
      browser = await type.launch(options);
      const context = await browser.newContext({ serviceWorkers: 'block' });
      const page = await context.newPage();
      await page.goto(`http://127.0.0.1:${address.port}/set`);
      const http = metadata(await context.cookies(`http://127.0.0.1:${address.port}`));
      await page.goto(`http://127.0.0.2:${crossSiteAddress.port}/`);
      await Promise.all([page.waitForURL(`http://127.0.0.1:${address.port}/check`), page.getByRole('button', { name: 'Probe synthetic POST' }).click()]);
      const crossSitePostCookieSent = await page.locator('#result').textContent() === 'sent';
      await context.clearCookies();
      await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== 'https://clinic-a.session.test') return route.abort('blockedbyclient');
        return route.fulfill({ contentType: 'text/html', headers: { 'Set-Cookie': '__Host-synthetic_response=fixed-synthetic; Path=/; Secure; HttpOnly; SameSite=Lax' }, body: '<!doctype html><title>Synthetic response</title>' });
      });
      await page.goto('https://clinic-a.session.test/');
      const response = metadata(await context.cookies('https://clinic-a.session.test'));
      await context.addCookies([{ name: '__Host-synthetic_api', value: 'fixed-synthetic', url: 'https://clinic-a.session.test', httpOnly: true, secure: true, sameSite: 'Lax' }]);
      const api = metadata(await context.cookies('https://clinic-a.session.test'));
      const required = !crossSitePostCookieSent && http.length === 1 && response.length === 1 && api.length === 2
        && [...http, ...api].every(cookie => cookie.sameSite === 'Lax' && cookie.httpOnly)
        && api.every(cookie => cookie.secure && cookie.path === '/' && cookie.domain === 'clinic-a.session.test');
      results.push({ browser: name, version: browser.version(), http, crossSitePostCookieSent, response, api, required });
      if (!required) process.exitCode = 1;
    } catch { results.push({ browser: name, error: 'browser_launch_or_probe_failed', required: false }); process.exitCode = 1; }
    finally { await browser?.close(); }
  }
} finally {
  await Promise.all([server, crossSiteServer].map(instance => new Promise((resolve, reject) => { instance.close(error => error ? reject(error) : resolve()); })));
}
console.log(JSON.stringify({ syntheticOnly: true, realTlsTested: false, results }, null, 2));
