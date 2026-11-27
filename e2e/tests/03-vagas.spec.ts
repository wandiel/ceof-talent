import { expect, test } from '@playwright/test'
import { api, createCandidate, login, RUN } from './helpers'

test('ciclo da vaga e quadro do processo seletivo', async ({ page }) => {
  page.on('dialog', (d) => d.accept())
  await login(page)
  const ana = await createCandidate(page, `E2E Ana Souza ${RUN}`)
  const bruno = await createCandidate(page, `E2E Bruno Costa ${RUN}`)
  const depts = await api(page, 'GET', '/departments?per_page=50')
  const dept = depts.data.find((d: any) => d.is_active) ?? (await api(page, 'POST', '/departments', { name: `E2E Enfermagem ${RUN}`, is_active: true })).data
  const title = `E2E Técnico de Enfermagem ${RUN}`
  const { data: job } = await api(page, 'POST', '/jobs', {
    title, department_id: dept.id, professional_area: 'nursing', employment_type: 'clt', reason: 'new_position',
    positions_count: 1, description: 'Vaga criada pelos testes automáticos de interface (E2E).', is_public: false,
  })

  await page.goto(`/vagas/${job.id}`)
  await expect(page.getByText('Rascunho', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Enviar para aprovação' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Enviar para aprovação' }).click()
  await expect(page.getByText('Aguardando aprovação', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Aprovar', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Aprovar', exact: true }).click()
  await expect(page.getByText('Aprovada', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Publicar', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Publicar', exact: true }).click()
  await expect(page.getByText('Publicada', { exact: true }).first()).toBeVisible()

  await page.getByRole('tab', { name: 'Processo seletivo' }).click()
  await page.getByRole('button', { name: '+ Adicionar candidatos' }).click()
  const dlg = page.getByRole('dialog', { name: /Adicionar candidatos/ })
  for (const c of [ana, bruno]) await dlg.locator('label', { hasText: c.full_name }).locator('input').check()
  await dlg.getByRole('button', { name: 'Inscrever na vaga' }).click()
  await expect(page.getByText('2 candidatos inscritos na vaga.')).toBeVisible()

  const col = (stage: string) => page.locator(`section[data-stage="${stage}"]`)
  await col('applied').locator('li', { hasText: ana.full_name }).dragTo(col('screening'))
  await expect(col('screening').locator('li', { hasText: ana.full_name })).toBeVisible()
  await expect(page.getByText(`${ana.full_name}: Triagem.`)).toBeVisible()

  await page.getByRole('button', { name: `Movimentar ${bruno.full_name}` }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.selectOption('#move-stage', 'rejected')
  await page.selectOption('#move-reason', 'salary')
  await page.getByRole('dialog').getByRole('button', { name: 'Reprovar' }).click()
  await expect(col('rejected').locator('li', { hasText: bruno.full_name })).toBeVisible()
})
