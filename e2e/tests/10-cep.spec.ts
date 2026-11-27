import { expect, test } from '@playwright/test'

// A consulta externa (ViaCEP/BrasilAPI) é simulada: o teste verifica o preenchimento na tela.
test('CEP preenche cidade, UF e bairro no portal', async ({ page }) => {
  await page.route('**/api/v1/public/cep/76801000', (route) =>
    route.fulfill({ json: { data: { cep: '76801000', street: 'Avenida Sete de Setembro', neighborhood: 'Centro', city: 'Porto Velho', state: 'RO' } } }),
  )
  await page.route('**/api/v1/public/cep/99999999', (route) => route.fulfill({ status: 404, json: { message: 'CEP não encontrado.' } }))

  await page.goto('/trabalhe-conosco/cadastro')
  await page.fill('#p-cep', '76801000')
  await expect(page.locator('#p-cep')).toHaveValue('76801-000')
  await expect(page.locator('#p-city')).toHaveValue('Porto Velho')
  await expect(page.locator('#p-state')).toHaveValue('RO')
  await expect(page.locator('#p-neigh')).toHaveValue('Centro')
  await expect(page.getByTestId('cep-status')).toContainText('Avenida Sete de Setembro')

  await page.fill('#p-cep', '99999-999')
  await expect(page.getByTestId('cep-status')).toHaveText('CEP não encontrado. Preencha a cidade e a UF.')
  await expect(page.locator('#p-city')).toHaveValue('Porto Velho')
})
