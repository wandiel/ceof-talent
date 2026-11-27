import { cachedUntilMutation, http } from './http'
import type { Option } from './candidates'
import type { Paginated } from './types'

export type InterviewStatus = 'scheduled' | 'completed' | 'cancelled' | 'no_show'

export interface Criterion {
  id: string
  name: string
  description: string | null
}

export interface InterviewOptions {
  types: Option[]
  modes: Option[]
  statuses: Option[]
  recommendations: Option[]
  criteria: Criterion[]
  interviewers: { id: string; name: string; job_title: string | null }[]
  can_manage_criteria: boolean
}

export interface Evaluation {
  evaluator: { id: string; name: string }
  is_mine: boolean
  recommendation: string
  recommendation_label: string
  summary: string
  observations: string | null
  average_score: string
  scores: { criterion: string; name: string; score: number; comment: string | null }[]
  updated_at: string
}

export interface Interview {
  id: string
  type: string
  type_label: string
  mode: string
  mode_label: string
  status: InterviewStatus
  status_label: string
  status_reason: string | null
  scheduled_at: string
  ends_at: string
  duration_minutes: number
  location: string | null
  meeting_url: string | null
  interviewers?: { id: string; name: string }[]
  candidate: { id: string; name: string; desired_position: string | null } | null
  application: { id: string; stage: string; stage_label: string } | null
  job: { id: string; code: string; title: string } | null
  evaluations_count?: number
  my_evaluation_pending: boolean
  instructions?: string | null
  criteria?: Criterion[]
  scheduled_by?: string | null
  evaluations?: Evaluation[]
  evaluations_hidden?: boolean
  abilities?: { update: boolean; cancel: boolean; no_show: boolean; evaluate: boolean; view_candidate: boolean }
}

export interface InterviewPayload {
  type: string
  mode: string
  scheduled_at: string
  duration_minutes: number
  location: string | null
  meeting_url: string | null
  instructions: string | null
  interviewers: string[]
  criteria?: string[] | null
  force?: boolean
  move_to_stage?: boolean
}

export interface EvaluationPayload {
  recommendation: string
  summary: string
  observations: string | null
  scores: { criterion: string; score: number; comment: string | null }[]
}

export interface InterviewListParams {
  from?: string
  to?: string
  status?: string[]
  mine?: boolean
  pending_evaluation?: boolean
  search?: string
  sort?: string
  page?: number
  per_page?: number
}

export interface HistoryItem {
  id: number
  event: string
  event_label: string
  description: string | null
  reason: string | null
  user: string | null
  created_at: string
}

const optionsCache = cachedUntilMutation(() => http.get<{ data: InterviewOptions }>('/v1/interviews/options').then((r) => r.data.data))

export const interviewsApi = {
  async list(params: InterviewListParams): Promise<Paginated<Interview>> {
    const query: Record<string, unknown> = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== false))
    if (query.mine) query.mine = 1
    if (query.pending_evaluation) query.pending_evaluation = 1
    const { data } = await http.get<Paginated<Interview>>('/v1/interviews', { params: query })
    return data
  },
  options(): Promise<InterviewOptions> {
    return optionsCache.get()
  },
  forgetOptions() {
    optionsCache.forget()
  },
  async get(id: string): Promise<Interview> {
    const { data } = await http.get<{ data: Interview }>(`/v1/interviews/${id}`)
    return data.data
  },
  async forApplication(applicationId: string): Promise<Interview[]> {
    const { data } = await http.get<{ data: Interview[] }>(`/v1/applications/${applicationId}/interviews`)
    return data.data
  },
  async schedule(applicationId: string, payload: InterviewPayload): Promise<Interview> {
    const { data } = await http.post<{ data: Interview }>(`/v1/applications/${applicationId}/interviews`, payload)
    return data.data
  },
  async update(id: string, payload: InterviewPayload): Promise<Interview> {
    const { data } = await http.put<{ data: Interview }>(`/v1/interviews/${id}`, payload)
    return data.data
  },
  async cancel(id: string, reason: string): Promise<Interview> {
    const { data } = await http.post<{ data: Interview }>(`/v1/interviews/${id}/cancel`, { reason })
    return data.data
  },
  async noShow(id: string, note: string | null): Promise<Interview> {
    const { data } = await http.post<{ data: Interview }>(`/v1/interviews/${id}/no-show`, { note })
    return data.data
  },
  async evaluate(id: string, payload: EvaluationPayload): Promise<Interview> {
    const { data } = await http.put<{ data: Interview }>(`/v1/interviews/${id}/evaluation`, payload)
    return data.data
  },
  async history(id: string): Promise<HistoryItem[]> {
    const { data } = await http.get<{ data: HistoryItem[] }>(`/v1/interviews/${id}/history`)
    return data.data
  },
}

export interface CriterionItem extends Criterion {
  is_active: boolean
}

export const criteriaApi = {
  async list(): Promise<CriterionItem[]> {
    const { data } = await http.get<{ data: CriterionItem[] }>('/v1/evaluation-criteria')
    return data.data
  },
  async create(payload: { name: string; description: string | null; is_active: boolean }): Promise<CriterionItem> {
    const { data } = await http.post<{ data: CriterionItem }>('/v1/evaluation-criteria', payload)
    interviewsApi.forgetOptions()
    return data.data
  },
  async update(id: string, payload: { name: string; description: string | null; is_active: boolean }): Promise<CriterionItem> {
    const { data } = await http.put<{ data: CriterionItem }>(`/v1/evaluation-criteria/${id}`, payload)
    interviewsApi.forgetOptions()
    return data.data
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/v1/evaluation-criteria/${id}`)
    interviewsApi.forgetOptions()
  },
}

export interface SuggestionItem {
  id: string
  type: 'skill' | 'professional_area'
  type_label: string
  value: string
  value_label: string
  confidence: number
  evidence: string | null
  status: 'pending' | 'accepted' | 'dismissed'
  status_label: string
  document: string | null
  reviewed_by: string | null
  reviewed_at: string | null
}

export interface MatchResult {
  score: number
  items: { label: string; mandatory: boolean; matched: boolean; evidence: string | null }[]
  mandatory_missing: number
  notice: string
}

export const intelligenceApi = {
  async suggestions(candidateId: string): Promise<{ data: SuggestionItem[]; meta: { reading: boolean; can_review: boolean } }> {
    const { data } = await http.get(`/v1/candidates/${candidateId}/suggestions`)
    return data
  },
  async review(candidateId: string, suggestionId: string, action: 'accept' | 'dismiss'): Promise<SuggestionItem> {
    const { data } = await http.post<{ data: SuggestionItem }>(`/v1/candidates/${candidateId}/suggestions/${suggestionId}/${action}`)
    return data.data
  },
  async reread(candidateId: string, documentId: string): Promise<void> {
    await http.post(`/v1/candidates/${candidateId}/documents/${documentId}/read`)
  },
  async match(applicationId: string): Promise<MatchResult> {
    const { data } = await http.get<{ data: MatchResult }>(`/v1/applications/${applicationId}/match`)
    return data.data
  },
}

export const interviewStatusClasses: Record<InterviewStatus, string> = {
  scheduled: 'bg-sky-50 text-sky-700 ring-sky-200',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-200 line-through',
  no_show: 'bg-amber-50 text-amber-800 ring-amber-200',
}

/** "2026-12-03" + "14:30" no fuso do navegador -> ISO 8601 com deslocamento. */
export function toIso(date: string, time: string): string {
  return new Date(`${date}T${time}:00`).toISOString()
}

export function splitLocal(iso: string): { date: string; time: string } {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` }
}

export function formatWhen(iso: string, endIso?: string): string {
  const d = new Date(iso)
  const day = d.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
  const time = (x: Date) => x.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  return `${day} · ${time(d)}${endIso ? '–' + time(new Date(endIso)) : ''}`
}
