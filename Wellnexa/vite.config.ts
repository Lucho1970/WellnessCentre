import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: 'localhost', port: 5175, strictPort: true,
    proxy: {
      '/api/public/contact': { target: 'https://wellnexa.copihue.ca', changeOrigin: true, rewrite: () => '/api/v1/site-config' },
      '/api/public/services': { target: 'https://wellnexa.copihue.ca', changeOrigin: true, rewrite: () => '/api/v1/public/services' },
      '/api/public/practitioners': { target: 'https://wellnexa.copihue.ca', changeOrigin: true, rewrite: () => '/api/v1/public/practitioners' },
    },
  },
  preview: { host: 'localhost', port: 5176, strictPort: true },
})
