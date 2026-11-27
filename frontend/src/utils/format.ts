/** Formatação de valores para exibição (padrão brasileiro). */

export function onlyDigits(value: string | null | undefined): string {
  return (value ?? '').replace(/\D/g, '')
}

export function formatPhone(value: string | null | undefined): string {
  const d = onlyDigits(value)
  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
  return value ?? ''
}

/** Máscara progressiva de telefone para campos de digitação. */
export function maskPhone(value: string): string {
  const d = onlyDigits(value).slice(0, 11)
  if (d.length <= 2) return d.length ? `(${d}` : ''
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
}

export function maskCpf(value: string): string {
  const d = onlyDigits(value).slice(0, 11)
  return d
    .replace(/^(\d{3})(\d)/, '$1.$2')
    .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
}

export function maskCep(value: string): string {
  const d = onlyDigits(value).slice(0, 8)
  return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  // Datas puras (AAAA-MM-DD) não devem sofrer ajuste de fuso.
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value)
  return date.toLocaleDateString('pt-BR')
}

export function formatDateTime(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

/** "2021-03" -> "mar/2021" */
export function formatMonth(value: string | null | undefined): string {
  if (!value) return ''
  const [year, month] = value.split('-').map(Number)
  if (!year || !month) return value
  return new Date(year, month - 1, 1).toLocaleDateString('pt-BR', { month: 'short', year: 'numeric' }).replace('. de ', '/').replace(' de ', '/')
}

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
}

/** 30 meses -> "2 anos e 6 meses" */
export function formatExperience(months: number | null | undefined): string {
  if (!months) return 'Sem experiência informada'
  const years = Math.floor(months / 12)
  const rest = months % 12
  const parts = [years ? `${years} ano${years > 1 ? 's' : ''}` : '', rest ? `${rest} ${rest > 1 ? 'meses' : 'mês'}` : ''].filter(Boolean)
  return parts.join(' e ')
}

/**
 * Converte um valor digitado no formato brasileiro em número:
 * "1.500" → 1500, "1.500,50" → 1500.5, "1500,5" → 1500.5, "1500.50" → 1500.5.
 */
export function parseMoney(text: string): number | null {
  let t = text.replace(/[^\d.,]/g, '')
  if (!t) return null
  if (t.includes(',')) {
    t = t.replace(/\./g, '').replace(',', '.').replace(/,/g, '')
  } else if (/^\d+\.\d{1,2}$/.test(t)) {
    // "1500.5" / "1500.50": ponto como decimal (sem vírgula e com 1–2 casas).
  } else {
    t = t.replace(/\./g, '') // "1.500" / "1.500.000": pontos de milhar
  }
  const n = Number(t)
  return Number.isFinite(n) ? Math.round(n * 100) / 100 : null
}
