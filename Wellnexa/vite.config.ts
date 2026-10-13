import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: { host: 'localhost', port: 5175, strictPort: true },
  preview: { host: 'localhost', port: 5176, strictPort: true },
})
