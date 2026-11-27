import { expect, test } from '@playwright/test'
import { login } from './helpers'

test.describe('Acesso ao sistema', () => {
  test('página protegida redireciona para o login', async ({ page }) => {
    await page.goto('/candidatos')
    await expect(page).toHaveURL(/\/entrar\?redirect=/)
  })

  test('senha errada mostra mensagem genérica', async ({ page }) => {
    await page.goto('/entrar')
    await page.fill('#email', `inexistente.${Date.now()}@ceof.test`)
    await page.fill('#password', 'SenhaErrada@123')
    await page.click('button[type=submit]')
    await expect(page.getByText('E-mail ou senha inválidos.').first()).toBeVisible()
  })

  test('login, menu por perfil e saída', async ({ page }) => {
    await login(page)
    for (const item of ['Vagas', 'Banco de talentos', 'Relatórios', 'Usuários', 'LGPD e privacidade', 'Auditoria']) {
      await expect(page.getByRole('link', { name: item, exact: true })).toBeVisible()
    }
    await page.locator('header button[aria-expanded]').click()
    await page.getByRole('menuitem', { name: 'Sair' }).click()
    await expect(page).toHaveURL(/\/entrar/)
    await page.goto('/usuarios')
    await expect(page).toHaveURL(/\/entrar/)
  })
})
