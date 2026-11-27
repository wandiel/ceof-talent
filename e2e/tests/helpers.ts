import { expect, type Page } from '@playwright/test'
import { fileURLToPath } from 'node:url'

export const ADMIN_EMAIL = process.env.E2E_EMAIL || 'admin@ceof.test'
export const ADMIN_PASSWORD = process.env.E2E_PASSWORD || 'Admin@Seguro2026'

/** Sufixo único para os dados criados por esta execução. */
export const RUN = Date.now().toString(36).slice(-6)

export const fixture = (name: string) => fileURLToPath(new URL(`../fixtures/${name}`, import.meta.url))

export async function login(page: Page, email = ADMIN_EMAIL, password = ADMIN_PASSWORD) {
  await page.goto('/entrar')
  await page.fill('#email', email)
  await page.fill('#password', password)
  await page.click('button[type=submit]')
  await page.waitForURL((u) => !u.pathname.startsWith('/entrar'))
  await expect(page.getByRole('heading').first()).toBeVisible()
}

/** Chamada à API com a sessão do navegador (cookie + CSRF), usada para preparar dados. */
export async function api<T = any>(page: Page, method: string, path: string, data?: unknown): Promise<T> {
  const cookies = await page.context().cookies()
  const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const base = new URL(page.url()).origin
  const res = await page.request.fetch(`${base}/api/v1${path}`, {
    method,
    data: data as any,
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', Origin: base, Referer: `${base}/` },
  })
  if (!res.ok()) throw new Error(`${method} ${path}: ${res.status()} ${(await res.text()).slice(0, 300)}`)
  return res.status() === 204 ? (undefined as T) : res.json()
}

export async function createCandidate(page: Page, name: string, extra: Record<string, unknown> = {}) {
  const slug = name.toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '.')
  const { data } = await api(page, 'POST', '/candidates', {
    full_name: name,
    email: `${slug}.${RUN}@exemplo.test`,
    city: 'Manaus',
    state: 'AM',
    professional_area: 'nursing',
    education_level: 'technical',
    desired_position: 'Técnico de enfermagem',
    source: 'internal',
    ...extra,
  })
  return data as { id: string; full_name: string; email: string }
}

/** Cria e publica uma vaga pública (rascunho → aprovação → publicação). */
export async function createPublishedJob(page: Page, title: string) {
  const depts = await api(page, 'GET', '/departments?per_page=50')
  let dept = depts.data.find((d: any) => d.is_active)
  if (!dept) dept = (await api(page, 'POST', '/departments', { name: `E2E Enfermagem ${RUN}`, is_active: true })).data
  const { data: job } = await api(page, 'POST', '/jobs', {
    title,
    department_id: dept.id,
    professional_area: 'nursing',
    employment_type: 'clt',
    reason: 'new_position',
    positions_count: 1,
    description: 'Vaga criada pelos testes automáticos de interface (E2E).',
    is_public: true,
    requirements: [{ description: 'COREN ativo', is_mandatory: true }],
  })
  for (const action of ['submit', 'approve', 'publish']) await api(page, 'POST', `/jobs/${job.id}/transition`, { action })
  return job as { id: string; title: string; code: string }
}
