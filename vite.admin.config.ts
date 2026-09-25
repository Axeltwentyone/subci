import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/** Tableau de bord d'administration (app séparée de la PWA, même design system). */
export default defineConfig({
  root: 'admin',
  plugins: [react(), tailwindcss()],
  server: {
    port: 5174,
    strictPort: true,
    proxy: { '/api': 'http://127.0.0.1:8000' },
    fs: { allow: ['..'] },
  },
  preview: { port: 4174, proxy: { '/api': 'http://127.0.0.1:8000' } },
  build: { outDir: '../dist-admin', emptyOutDir: true },
})
