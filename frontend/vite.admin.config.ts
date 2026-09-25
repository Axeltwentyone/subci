import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

/** Tableau de bord d'administration (app séparée de la PWA, même design system), installable. */
export default defineConfig({
  root: 'admin',
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'favicon-32.png', 'apple-touch-icon.png', 'badge-96.png'],
      manifest: {
        id: '/?app=admin',
        name: 'Sub.ci Admin',
        short_name: 'Sub.ci Admin',
        description: 'Piloter Sub.ci : offres, paiements, versements.',
        lang: 'fr',
        display: 'standalone',
        theme_color: '#16130F',
        background_color: '#16130F',
        start_url: '/',
        scope: '/',
        icons: [
          { src: 'pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
        shortcuts: [
          { name: 'Offres à valider', short_name: 'Offres', url: '/offers', icons: [{ src: 'pwa-192.png', sizes: '192x192' }] },
          { name: 'Versements', short_name: 'Versements', url: '/payouts', icons: [{ src: 'pwa-192.png', sizes: '192x192' }] },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        importScripts: ['push-sw.js'],
        navigateFallbackDenylist: [/^\/api\//],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
      },
      devOptions: { enabled: false },
    }),
  ],
  server: {
    port: 5174,
    strictPort: true,
    proxy: { '/api': 'http://127.0.0.1:8000' },
    fs: { allow: ['..'] },
  },
  preview: { port: 4174, strictPort: true, proxy: { '/api': 'http://127.0.0.1:8000' } },
  build: { outDir: '../dist-admin', emptyOutDir: true },
})
