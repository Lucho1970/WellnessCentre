import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const config = readFileSync(new URL('../public/.htaccess', import.meta.url), 'utf8');
test('HTML cache overrides are scoped to the SPA shell only', () => {
  const block = config.match(/<Files "index\.html">([\s\S]*?)<\/Files>/)?.[1];
  assert.ok(block);
  assert.match(block, /<IfModule mod_expires\.c>\s+ExpiresActive Off/);
  assert.match(block, /<IfModule mod_headers\.c>/);
  assert.match(block, /Header onsuccess unset Cache-Control/);
  assert.match(block, /Header always set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"/);
  assert.match(block, /Header onsuccess unset Expires/);
  assert.match(block, /Header always set Expires "Thu, 01 Jan 1970 00:00:00 GMT"/);
  assert.doesNotMatch(config.replace(/<Files "index\.html">[\s\S]*?<\/Files>/, ''), /\b(?:Header|ExpiresActive)\b/);
});
test('API exclusion and static-file handling stay ahead of SPA fallback', () => {
  const api = config.indexOf('RewriteRule ^api(?:/|$) - [L]');
  const file = config.indexOf('RewriteCond %{REQUEST_FILENAME} -f');
  const fallback = config.indexOf('RewriteRule ^ index.html [L]');
  assert.ok(api > 0 && file > api && fallback > file);
});
