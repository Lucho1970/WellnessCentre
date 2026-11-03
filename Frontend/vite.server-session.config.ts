import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
  plugins:[react()], cacheDir:'node_modules/.vite-server-session',
  define:{__APP_SURFACE__:JSON.stringify('portal'),'import.meta.env.VITE_SERVER_SESSIONS_ENABLED':JSON.stringify('true'),'import.meta.env.VITE_API_BASE_URL':JSON.stringify('/api/v1')},
  server:{host:'127.0.0.1',port:5198,strictPort:true},
});
