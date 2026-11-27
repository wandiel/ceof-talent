import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// O frontend é sempre acessado através do Nginx (http://localhost:8080 em dev),
// que encaminha /api ao Laravel. Por isso não há proxy configurado aqui.
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    hmr: {
      // Porta pública do Nginx, para o WebSocket do HMR atravessar o proxy.
      clientPort: Number(process.env.VITE_HMR_CLIENT_PORT ?? 8080),
    },
    watch: {
      // Bind mounts em Windows/macOS nem sempre propagam eventos de arquivo.
      usePolling: process.env.VITE_USE_POLLING === 'true',
    },
  },
  build: {
    sourcemap: false,
    chunkSizeWarningLimit: 800,
  },
})
