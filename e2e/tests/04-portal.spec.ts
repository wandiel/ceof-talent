import { expect, test } from '@playwright/test'
import { api, createPublishedJob, fixture, login, RUN } from './helpers'

test('candidatura pelo portal Trabalhe conosco chega ao RH', async ({ page, browser }) => {
  await login(page)
  const job = await createPublishedJob(page, `E2E Auxiliar Administrativo ${RUN}`)

  // Visitante sem login.
  const visitor = await browser.newPage()
  await visitor.goto('/trabalhe-conosco')
  await visitor.fill('input[type=search]', job.title)
  await visitor.getByRole('link', { name: new RegExp(job.title) }).click()
  await visitor.getByRole('link', { name: 'Quero me candidatar' }).click()
  await visitor.getByRole('button', { name: 'Enviar candidatura' }).click()
  await expect(visitor.getByText('Anexe o seu currículo.')).toBeVisible()

  const name = `E2E Fernanda Lima ${RUN}`
  await visitor.fill('#p-name', name)
  await visitor.fill('#p-email', `fernanda.${RUN}@exemplo.test`)
  await visitor.fill('#p-phone', '(92) 99123-4567')
  await visitor.fill('#p-city', 'Manaus')
  await visitor.selectOption('#p-area', 'administrative')
  await visitor.selectOption('#p-education', 'higher')
  await visitor.setInputFiles('#p-resume', fixture('curriculo.pdf'))
  await visitor.check('#p-privacy')
  await visitor.getByRole('button', { name: 'Enviar candidatura' }).click()
  await expect(visitor.getByRole('heading', { name: 'Recebemos o seu currículo' })).toBeVisible()
  await expect(visitor.getByTestId('protocol')).toHaveText(/^TC-\d{6}-[A-Z0-9]{6}$/)
  await visitor.close()

  // O RH vê o candidato inscrito na vaga.
  const apps = await api(page, 'GET', `/jobs/${job.id}/applications`)
  expect(JSON.stringify(apps)).toContain(name)
})
