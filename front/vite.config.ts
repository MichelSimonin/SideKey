/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    // Le front appelle /api sur sa propre origine : Vite relaie vers Nginx.
    // Même origine = pas de CORS en dev, et le cookie du refresh token reste SameSite=Strict.
    proxy: {
      '/api': {
        target: process.env.API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
      // Jaquettes téléversées par le back-office (F-09) : servies par Nginx, relayées sur la même origine.
      '/media': {
        target: process.env.API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    // Origine HTTPS comme en recette et en production : les cookies Secure (sk_csrf) sont alors lisibles par le script.
    environmentOptions: { jsdom: { url: 'https://localhost/' } },
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/test/**', 'src/main.tsx'],
    },
  },
})
