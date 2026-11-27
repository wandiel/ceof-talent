import { http } from './http'
import type { Option } from './candidates'

export interface ReportFilterParams {
  from?: string | null
  to?: string | null
  department?: string | null
  unit?: string | null
  area?: string | null
}

export interface FilterOptions {
  department_locked: boolean
  departments: { id: string; name: string }[]
  units: { id: string; name: string }[]
  areas: Option[]
}

export interface Dashboard {
  kpis: {
    total_candidates: number
    new_candidates: number
    active_candidates: number
    open_jobs: number
    open_positions: number
    active_processes: number
    scheduled_interviews: number
    approvals: number
    hires: number
    avg_days_to_hire: number | null
  }
  monthly: { month: string; label: string; candidates: number; applications: number; hires: number }[]
  funnel: { stages: { stage: string; label: string; count: number }[]; rejected: number; withdrawn: number; total: number }
  by_department: { id: string; name: string; open_jobs: number; applications: number; hires: number }[]
  by_area: { area: string; label: string; count: number }[]
  upcoming_interviews: { id: string; scheduled_at: string; type_label: string; candidate: string; job: string }[]
}

export type ColumnType = 'text' | 'number' | 'decimal' | 'percent' | 'date' | 'datetime'

export interface ReportColumn {
  key: string
  label: string
  type: ColumnType
}

export interface ReportSummary {
  key: string
  group: string
  title: string
  description: string
}

export interface ReportData {
  key: string
  title: string
  description: string
  columns: ReportColumn[]
  rows: Record<string, string | number | null>[]
}

export type ExportFormat = 'pdf' | 'xlsx' | 'csv'

/** Remove filtros vazios para não poluir a URL. */
export function cleanParams(p: ReportFilterParams): Record<string, string> {
  return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== null && v !== undefined && v !== '')) as Record<string, string>
}

export const dashboardApi = {
  async get(params: ReportFilterParams) {
    const { data } = await http.get<{ data: Dashboard; meta: FilterOptions & { generated_at: string; can_view_reports: boolean; filters: ReportFilterParams } }>('/v1/dashboard', { params: cleanParams(params) })
    return data
  },
}

export const reportsApi = {
  async index() {
    const { data } = await http.get<{ data: ReportSummary[]; meta: FilterOptions & { can_export: boolean; formats: ExportFormat[] } }>('/v1/reports')
    return data
  },
  async show(key: string, params: ReportFilterParams) {
    const { data } = await http.get<{ data: ReportData; meta: { total: number; shown: number; filters_label: { label: string; value: string }[] } }>(`/v1/reports/${key}`, { params: cleanParams(params) })
    return data
  },
  /** Baixa o arquivo pelo navegador, com o nome que o servidor sugere. */
  async download(key: string, format: ExportFormat, params: ReportFilterParams) {
    const res = await http.get<Blob>(`/v1/reports/${key}/export/${format}`, { params: cleanParams(params), responseType: 'blob', timeout: 120000 })
    const disposition = String(res.headers['content-disposition'] ?? '')
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `relatorio.${format}`
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}

const nf = new Intl.NumberFormat('pt-BR')

export function formatCell(value: string | number | null | undefined, type: ColumnType): string {
  if (value === null || value === undefined || value === '') return '—'
  switch (type) {
    case 'number':
      return nf.format(Number(value))
    case 'decimal':
      return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(Number(value))
    case 'percent':
      return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(value)) + '%'
    case 'date': {
      const [y, m, d] = String(value).slice(0, 10).split('-')
      return `${d}/${m}/${y}`
    }
    case 'datetime': {
      const [date, time] = String(value).split(' ')
      const [y, m, d] = (date ?? '').split('-')
      return `${d}/${m}/${y} ${time ?? ''}`.trim()
    }
    default:
      return String(value)
  }
}

export const formatNumber = (n: number) => nf.format(n)

/** Data local no formato AAAA-MM-DD. */
export function isoDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export const PERIODS = [
  { value: '30d', label: 'Últimos 30 dias' },
  { value: 'month', label: 'Este mês' },
  { value: '3m', label: 'Últimos 3 meses' },
  { value: '12m', label: 'Últimos 12 meses' },
  { value: 'year', label: 'Este ano' },
  { value: 'all', label: 'Todo o período' },
  { value: 'custom', label: 'Personalizado' },
] as const

export type PeriodPreset = (typeof PERIODS)[number]['value']

export function periodRange(preset: PeriodPreset): { from: string | null; to: string | null } {
  const today = new Date()
  const to = isoDate(today)
  const y = today.getFullYear()
  const m = today.getMonth()
  switch (preset) {
    case '30d':
      return { from: isoDate(new Date(y, m, today.getDate() - 29)), to }
    case 'month':
      return { from: isoDate(new Date(y, m, 1)), to }
    case '3m':
      return { from: isoDate(new Date(y, m - 2, 1)), to }
    case '12m':
      return { from: isoDate(new Date(y, m - 11, 1)), to }
    case 'year':
      return { from: isoDate(new Date(y, 0, 1)), to }
    default:
      return { from: null, to: null }
  }
}
