import { expect, test } from '@playwright/test'
import { RUN, login } from './helpers'

// Um departamento cadastrado deve aparecer no formulário de vaga sem recarregar a página.
test('departamento recém-cadastrado aparece no formulário de nova vaga', async ({ page }) => {
  await login(page)
  const nome = `E2E Departamento novo ${RUN}`

  // Abre o formulário primeiro (as opções ficam guardadas em memória).
  await page.goto('/vagas/nova')
  await expect(page.locator('#j-dept')).toBeVisible()
  await expect(page.locator('#j-dept option', { hasText: nome })).toHaveCount(0)

  // Cadastra o departamento pela tela, navegando dentro da aplicação (sem recarregar).
  await page.evaluate(() => window.history.pushState({}, '', '/departamentos'))
  await page.evaluate(() => window.dispatchEvent(new PopStateEvent('popstate')))
  await page.getByRole('button', { name: '+ Novo departamento' }).click()
  await page.fill('#dep-name', nome)
  await page.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByRole('cell', { name: nome })).toBeVisible()

  await page.evaluate(() => window.history.pushState({}, '', '/vagas/nova'))
  await page.evaluate(() => window.dispatchEvent(new PopStateEvent('popstate')))
  await expect(page.locator('#j-dept option', { hasText: nome })).toHaveCount(1)
})
