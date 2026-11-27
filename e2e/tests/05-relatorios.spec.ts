import { expect, test } from '@playwright/test'
import { login } from './helpers'

test('dashboard e exportação de relatório', async ({ page }) => {
  await login(page)
  await expect(page.getByText(/Vagas abertas/i).first()).toBeVisible()

  await page.goto('/relatorios')
  const [csv] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar CSV' }).first().click()])
  expect(csv.suggestedFilename()).toMatch(/\.csv$/)
  const [pdf] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar PDF' }).first().click()])
  expect(pdf.suggestedFilename()).toMatch(/\.pdf$/)
})
