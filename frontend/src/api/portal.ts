import { ensureCsrfCookie, http } from './http'
import type { Option } from './candidates'

export interface CepAddress {
  cep: string
  street: string | null
  neighborhood: string | null
  city: string
  state: string
}

export interface PublicJob {
  id: string
  code: string
  title: string
  department: string | null
  unit: { name: string; city: string | null; state: string | null } | null
  professional_area: string
  professional_area_label: string
  employment_type_label: string
  positions_count: number
  work_schedule: string | null
  salary: { min: string | null; max: string | null } | null
  education_level_min_label: string | null
  closes_on: string | null
  published_at: string | null
  summary: string
  description?: string
  responsibilities?: string | null
  benefits?: string | null
  requirements?: { description: string; is_mandatory: boolean }[]
}

export interface PrivacyInfo {
  version: string
  updated_at: string
  controller: string
  dpo_name: string | null
  dpo_email: string | null
  talent_pool_retention_months: number
}

export interface PortalOptions {
  areas: Option[]
  education_levels: Option[]
  states: string[]
  max_file_mb: number
  max_extra_documents: number
  privacy: PrivacyInfo
}

export interface PortalReceipt {
  protocol: string
  received_at: string
  job: { code: string; title: string } | null
  email: string
}

let optionsCache: Promise<PortalOptions> | null = null

export const portalApi = {
  async jobs(params: { search?: string; area?: string }) {
    const { data } = await http.get<{ data: PublicJob[] }>('/v1/public/jobs', { params })
    return data.data
  },
  async job(id: string) {
    const { data } = await http.get<{ data: PublicJob }>(`/v1/public/jobs/${id}`)
    return data.data
  },
  options() {
    optionsCache ??= http.get<{ data: PortalOptions }>('/v1/public/options').then((r) => r.data.data).catch((e) => {
      optionsCache = null
      throw e
    })
    return optionsCache
  },
  /** Endereço pelo CEP (null quando não encontrado ou fora do ar). */
  async cep(cep: string): Promise<CepAddress | null> {
    try {
      const { data } = await http.get<{ data: CepAddress }>(`/v1/public/cep/${cep}`, { timeout: 10000 })
      return data.data
    } catch {
      return null
    }
  },
  async submit(form: FormData) {
    await ensureCsrfCookie()
    const { data } = await http.post<{ data: PortalReceipt }>('/v1/public/applications', form, { timeout: 120000 })
    return data.data
  },
}

const RECEIPT_KEY = 'ceof-portal-receipt'
export const receiptStore = {
  save: (r: PortalReceipt) => sessionStorage.setItem(RECEIPT_KEY, JSON.stringify(r)),
  load: (): PortalReceipt | null => {
    try {
      return JSON.parse(sessionStorage.getItem(RECEIPT_KEY) ?? 'null')
    } catch {
      return null
    }
  },
}

export function formatMoney(v: string | null): string | null {
  return v === null ? null : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

export function formatDateBr(iso: string | null): string {
  if (!iso) return ''
  const [y, m, d] = iso.slice(0, 10).split('-')
  return `${d}/${m}/${y}`
}
