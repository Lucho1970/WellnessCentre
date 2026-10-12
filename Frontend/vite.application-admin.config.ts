import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig(({ command }) => ({
  plugins: [react(), { name: 'central-admin-entry', configureServer(server) {
    server.middlewares.use((req, _res, next) => { if (req.url?.split('?')[0].match(/^\/admin\/?$/)) req.url = '/admin/central.html'; next(); });
  } }],
  base: '/admin/', cacheDir: 'node_modules/.vite-application-admin',
  define: { __APP_SURFACE__: JSON.stringify('application-admin'), 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api/v1') },
  build: { outDir: 'dist/application-admin', emptyOutDir: true, rollupOptions: { input: 'central.html' } },
  server: { port: 5185, strictPort: true },
}));
