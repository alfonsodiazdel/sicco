import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import vueDevTools from 'vite-plugin-vue-devtools'

// La API (nginx en :8000) se sirve a través del mismo origen que la SPA,
// igual que en producción (sicco.app y sicco.app/api), para usar la cookie de sesión.
const API_URL = process.env.VITE_API_PROXY ?? 'http://localhost:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss(), vueDevTools()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': API_URL,
      '/sanctum': API_URL,
    },
  },
})
