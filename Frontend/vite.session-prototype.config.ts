import { defineConfig } from 'vite';

export default defineConfig({
  envDir: false,
  cacheDir: 'node_modules/.vite-session-prototype',
  server: { host: '127.0.0.1', port: 5196, strictPort: true, hmr: false, allowedHosts: ['clinic-a.session.test', 'clinic-b.session.test', 'admin.session.test'] },
});
