import { expect, test } from '@playwright/test'
import { login } from './helpers'

// Valores digitados no formato brasileiro: "1.500" é mil e quinhentos, não 1,5.
test('campo de salário entende ponto de milhar e vírgula decimal', async ({ page }) => {
  await login(page)
  await page.goto('/vagas/nova')
  const min = page.locator('#j-smin')
  const max = page.locator('#j-smax')

  await min.fill('1.500')
  await max.fill('2.350,5')
  await page.locator('#j-title').click()
  await expect(min).toHaveValue('1.500,00')
  await expect(max).toHaveValue('2.350,50')

  await min.fill('1800')
  await page.locator('#j-title').click()
  await expect(min).toHaveValue('1.800,00')
})
