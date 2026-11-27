import { http } from './http'
import type { Option } from './candidates'

export interface PrivacyRequestItem {
  id: string
  protocol: string
  requester_name: string
  requester_email: string | null
  type: string
  type_label: string
  channel: string
  channel_label: string
  status: 'open' | 'in_progress' | 'completed' | 'rejected'
  status_label: string
  due_on: string
  overdue: boolean
  completed_at: string | null
  created_at: string
  handler: string | null
  candidate: { id: string; name: string; anonymized: boolean } | null
}

export interface PrivacyRequestDetail extends PrivacyRequestItem {
  description: string | null
  response: string | null
  requester_phone: string | null
  created_by: string | null
  history: { description: string; user: string; created_at: string }[]
}

export interface PrivacyOverview {
  requests: { open: number; overdue: number; completed_last_30_days: number }
  retention: {
    due_now: number
    upcoming_count: number
    upcoming: { id: string; name: string; expires_at: string }[]
    anonymized_total: number
    audit_purgeable: number
    settings: { talent_pool_months: number; no_consent_months: number; deleted_grace_days: number; keep_hired: boolean; audit_months: number; enabled: boolean }
  }
  consents: { talent_pool_active: number; talent_pool_revoked: number; privacy_notice: number; notice_version: string }
  processing_activities: { name: string; purpose: string; legal_basis: string; data: string; retention: string; shared_with: string }[]
  dpo: { name: string | null; email: string | null; controller: string }
}

export interface CandidatePrivacy {
  anonymized_at: string | null
  retention: { expires_at: string | null; has_open_process: boolean; talent_pool_months: number; no_consent_months: number }
  consents: {
    id: string
    purpose: string
    purpose_label: string
    version: string
    channel: string
    channel_label: string
    granted_at: string
    revoked_at: string | null
    revoked_reason: string | null
    recorded_by: string | null
    ip_address: string | null
  }[]
  submissions: { protocol: string; created_at: string; job: string | null; is_new_candidate: boolean; payload: Record<string, unknown> }[]
  requests: { id: string; protocol: string; type_label: string; status_label: string; status: string; due_on: string }[]
  purposes: Option[]
  notice_version: string
}

export interface AuditEntry {
  id: number
  event: string
  description: string | null
  user: { id: string; name: string } | null
  subject: { type: string; id: number } | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  context: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
}

export interface PageMeta {
  total: number
  current_page: number
  last_page: number
  per_page: number
  from: number | null
  to: number | null
}

export const privacyApi = {
  async overview() {
    return (await http.get<{ data: PrivacyOverview }>('/v1/privacy/overview')).data.data
  },
  async options() {
    return (await http.get<{ data: { types: Option[]; statuses: Option[]; channels: Option[]; deadline_days: number } }>('/v1/privacy/requests/options')).data.data
  },
  async requests(params: Record<string, unknown>) {
    return (await http.get<{ data: PrivacyRequestItem[]; meta: PageMeta }>('/v1/privacy/requests', { params })).data
  },
  async request(id: string) {
    return (await http.get<{ data: PrivacyRequestDetail }>(`/v1/privacy/requests/${id}`)).data.data
  },
  async createRequest(payload: Record<string, unknown>) {
    return (await http.post<{ data: PrivacyRequestItem }>('/v1/privacy/requests', payload)).data.data
  },
  async updateRequest(id: string, payload: Record<string, unknown>) {
    return (await http.put<{ data: PrivacyRequestItem }>(`/v1/privacy/requests/${id}`, payload)).data.data
  },
  async candidate(id: string) {
    return (await http.get<{ data: CandidatePrivacy }>(`/v1/candidates/${id}/privacy`)).data.data
  },
  async addConsent(id: string, payload: { purpose: string; evidence: string }) {
    await http.post(`/v1/candidates/${id}/consents`, payload)
  },
  async revokeConsent(id: string, consent: string, reason: string) {
    await http.post(`/v1/candidates/${id}/consents/${consent}/revoke`, { reason })
  },
  async anonymize(id: string, payload: { reason: string; confirm: string; request?: string | null }) {
    await http.post(`/v1/candidates/${id}/anonymize`, payload)
  },
  async exportData(id: string) {
    const res = await http.get<Blob>(`/v1/candidates/${id}/privacy/export`, { responseType: 'blob' })
    const name = /filename="?([^";]+)"?/.exec(String(res.headers['content-disposition'] ?? ''))?.[1] ?? 'dados-titular.json'
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
  async audit(params: Record<string, unknown>) {
    return (await http.get<{ data: AuditEntry[]; meta: PageMeta & { groups: Option[]; users: { id: string; name: string }[] } }>('/v1/audit-logs', { params })).data
  },
}

export const requestStatusClasses: Record<string, string> = {
  open: 'bg-amber-50 text-amber-800 ring-amber-200',
  in_progress: 'bg-brand-50 text-brand-800 ring-brand-200',
  completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
  rejected: 'bg-slate-100 text-slate-700 ring-slate-200',
}
