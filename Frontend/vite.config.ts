import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
export const surfaceConfig = (portal: boolean) => defineConfig(({ mode, command, isPreview }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const url = portal ? env.VITE_PORTAL_URL : env.VITE_PUBLIC_URL;
  const base = command === 'serve' && !isPreview ? '/' : new URL(url || (portal ? '/portal/' : '/'), 'https://build.invalid').pathname.replace(/\/?$/, '/');
  return {
    plugins: [react()], base,
    define: { __APP_SURFACE__: JSON.stringify(portal ? 'portal' : 'public') },
    build: { outDir: `dist/${portal ? 'portal' : 'public'}`, emptyOutDir: true },
    server: { port: portal ? 5174 : 5173, strictPort: true },
    preview: { port: portal ? 4174 : 4173, strictPort: true },
  };
});
export default surfaceConfig(false);
