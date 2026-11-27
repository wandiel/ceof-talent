import { expect, test } from '@playwright/test'
import { api, fixture, login, RUN } from './helpers'

test('currículo enviado sem vaga aparece no banco de talentos', async ({ page, browser }) => {
  await login(page)
  const visitor = await browser.newPage()
  await visitor.goto('/trabalhe-conosco/cadastro')
  const name = `E2E Joana Espontanea ${RUN}`
  await visitor.fill('#p-name', name)
  await visitor.fill('#p-email', `joana.espontanea.${RUN}@exemplo.test`)
  await visitor.fill('#p-phone', '(92) 99123-4567')
  await visitor.fill('#p-city', 'Manaus')
  await visitor.selectOption('#p-area', 'administrative')
  await visitor.selectOption('#p-education', 'higher')
  await visitor.setInputFiles('#p-resume', fixture('curriculo.pdf'))
  await visitor.check('#p-privacy')
  await visitor.getByRole('button', { name: /Enviar/ }).click()
  await expect(visitor.getByRole('heading', { name: 'Recebemos o seu currículo' })).toBeVisible()
  await visitor.close()

  const list = await api(page, 'GET', `/candidates?search=${encodeURIComponent('Joana Espontanea')}`)
  expect(JSON.stringify(list)).toContain(name)
  await page.goto('/candidatos')
  await expect(page.getByText(name)).toBeVisible()
})
