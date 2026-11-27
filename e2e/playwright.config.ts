import { defineConfig, devices } from '@playwright/test'

/**
 * Testes de interface do CEOF TALENT (Fase 11).
 * Rodam contra o sistema já em execução e criam dados próprios, identificados
 * com o prefixo "E2E". Use em ambiente de desenvolvimento ou homologação.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  outputDir: './resultados/artefatos',
  reporter: [['list'], ['html', { outputFolder: './resultados/relatorio', open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    locale: 'pt-BR',
    timezoneId: 'America/Manaus',
    acceptDownloads: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
    viewport: { width: 1366, height: 900 },
  },
})
