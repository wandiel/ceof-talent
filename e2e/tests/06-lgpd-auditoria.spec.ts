import { expect, test } from '@playwright/test'
import { createCandidate, login, RUN } from './helpers'
import { readFileSync } from 'node:fs'

test('solicitação de titular, exportação, anonimização e auditoria', async ({ page }) => {
  await login(page)
  const cand = await createCandidate(page, `E2E Titular Teste ${RUN}`)

  await page.goto('/lgpd')
  await expect(page.getByRole('heading', { name: 'Política de retenção' })).toBeVisible()
  await page.getByRole('button', { name: '+ Registrar solicitação' }).click()
  await page.selectOption('#rq-type', 'deletion')
  await page.fill('#rq-cand', cand.email)
  await page.getByRole('button', { name: new RegExp(cand.full_name) }).click()
  await page.getByRole('button', { name: 'Registrar', exact: true }).click()
  await expect(page.getByText(/Solicitação LGPD-\d{6}-\w+ registrada/)).toBeVisible()

  await page.getByRole('link', { name: cand.full_name }).click()
  await expect(page.getByRole('heading', { name: 'Consentimentos' })).toBeVisible()
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar dados do titular (JSON)' }).click()])
  expect(readFileSync(await download.path(), 'utf8')).toContain(cand.full_name)

  await page.getByRole('button', { name: 'Anonimizar cadastro' }).click()
  await page.fill('#pv-reason', 'Pedido de eliminação (teste E2E)')
  await page.fill('#pv-confirm', 'ANONIMIZAR')
  await page.getByRole('button', { name: 'Anonimizar definitivamente' }).click()
  await expect(page.getByText('Cadastro anonimizado.')).toBeVisible()
  await expect(page.getByRole('heading', { name: /Titular anonimizado #\d+/ })).toBeVisible()

  await page.goto('/auditoria')
  await page.selectOption('select[aria-label="Área"]', 'privacy')
  await expect(page.getByText(/anonimizado/i).first()).toBeVisible()
})
