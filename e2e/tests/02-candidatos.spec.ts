import { expect, test } from '@playwright/test'
import { fixture, login, RUN } from './helpers'

test('cadastro de candidato com currículo, busca e histórico', async ({ page }) => {
  const name = `E2E Joana Pereira ${RUN}`
  await login(page)
  await page.goto('/candidatos/novo')

  await page.getByRole('button', { name: 'Cadastrar candidato' }).click()
  await expect(page.getByText('O campo nome completo é obrigatório.')).toBeVisible()

  await page.fill('#c-name', name)
  await page.fill('#c-email', `joana.${RUN}@exemplo.test`)
  await page.fill('#c-phone', '92991112233')
  await page.fill('#c-city', 'Manaus')
  await page.selectOption('#c-state', 'AM')
  await page.selectOption('#c-area', 'nursing')
  await page.selectOption('#c-edu', 'technical')
  await page.fill('#c-position', 'Técnica de enfermagem')
  const skill = page.getByLabel('Nova competência')
  await skill.fill('Oftalmologia')
  await skill.press('Enter')
  await page.getByRole('button', { name: 'Cadastrar candidato' }).click()
  await page.waitForURL(/candidatos\/[0-9a-f-]{36}\?aba=documentos/)
  await expect(page.getByRole('heading', { name })).toBeVisible()

  // Arquivo disfarçado é recusado; PDF real é aceito e pode ser baixado.
  await page.setInputFiles('[data-testid=document-input]', fixture('falso.pdf'))
  await page.getByRole('button', { name: 'Enviar arquivo' }).click()
  await expect(page.getByText('O conteúdo do arquivo não corresponde à extensão .pdf.')).toBeVisible()
  await page.setInputFiles('[data-testid=document-input]', fixture('curriculo.pdf'))
  await page.getByRole('button', { name: 'Enviar arquivo' }).click()
  await expect(page.getByText('Documento anexado.')).toBeVisible()
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Baixar' }).first().click()])
  expect(download.suggestedFilename()).toBe('curriculo.pdf')

  // Busca no banco de talentos.
  await page.goto('/candidatos')
  await page.getByLabel('Busca rápida').fill(`Joana Pereira ${RUN}`)
  await expect(page.getByRole('link', { name: new RegExp(name) })).toBeVisible()

  // Histórico registra o download.
  await page.getByRole('link', { name: new RegExp(name) }).click()
  await page.getByRole('tab', { name: /Histórico/ }).click()
  await expect(page.getByText('Documento baixado').first()).toBeVisible()
})
