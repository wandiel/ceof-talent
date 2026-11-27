import { cachedUntilMutation, http } from './http'
import type { Option } from './candidates'
import type { Paginated } from './types'

export type JobStatus = 'draft' | 'pending_approval' | 'rejected' | 'approved' | 'published' | 'on_hold' | 'closed' | 'cancelled'
export type Stage = 'applied' | 'screening' | 'shortlisted' | 'interview' | 'technical' | 'approved' | 'hired' | 'rejected' | 'withdrawn'
export type JobAction = 'submit' | 'approve' | 'reject' | 'publish' | 'suspend' | 'close' | 'reopen' | 'cancel'

export interface IdName {
  id: string
  name: string
}

export interface JobOptions {
  statuses: Option[]
  employment_types: Option[]
  reasons: Option[]
  professional_areas: Option[]
  education_levels: Option[]
  stages: Option[]
  pipeline: Stage[]
  rejection_reasons: Option[]
  departments: IdName[]
  units: IdName[]
  positions: IdName[]
  can_create: boolean
  can_choose_department: boolean
  my_department_id: string | null
}

export interface JobListItem {
  id: string
  code: string
  title: string
  status: JobStatus
  status_label: string
  status_reason: string | null
  department: IdName
  unit: IdName | null
  professional_area: string
  professional_area_label: string
  employment_type: string
  employment_type_label: string
  positions_count: number
  applications_count?: number
  active_applications_count?: number
  hired_count?: number
  is_public: boolean
  closes_on: string | null
  requester: IdName | null
  created_at: string
  published_at: string | null
  closed_at: string | null
}

export interface Requirement {
  description: string
  is_mandatory: boolean
}

export interface Job extends JobListItem {
  position: IdName | null
  reason: string
  reason_label: string
  replaced_employee: string | null
  work_schedule: string | null
  salary_min: string | null
  salary_max: string | null
  show_salary: boolean
  education_level_min: string | null
  education_level_min_label: string | null
  description: string
  responsibilities: string | null
  benefits: string | null
  requirements: Requirement[]
  approver: IdName | null
  submitted_at: string | null
  approved_at: string | null
  hired_total: number
  abilities: {
    update: boolean
    view_applications: boolean
    add_applications: boolean
    move: boolean
    decide: boolean
    actions: { action: JobAction; label: string; needs_reason: boolean }[]
  }
}

export interface JobPayload {
  title: string
  department_id: string | null
  unit_id: string | null
  position_id: string | null
  professional_area: string
  employment_type: string
  reason: string
  replaced_employee: string | null
  positions_count: number
  work_schedule: string | null
  salary_min: number | string | null
  salary_max: number | string | null
  show_salary: boolean
  education_level_min: string | null
  description: string
  responsibilities: string | null
  benefits: string | null
  is_public: boolean
  closes_on: string | null
  requirements: Requirement[]
}

export interface StageHistoryEntry {
  id: number
  from_stage: Stage | null
  from_stage_label: string | null
  to_stage: Stage
  to_stage_label: string
  reason_label: string | null
  note: string | null
  user: string | null
  created_at: string
}

export interface ApplicationItem {
  id: string
  stage: Stage
  stage_label: string
  previous_stage: Stage | null
  rejection_reason: string | null
  rejection_reason_label: string | null
  source: string
  stage_changed_at: string
  hired_at: string | null
  created_at: string
  candidate?: {
    id: string
    full_name: string
    social_name: string | null
    desired_position: string | null
    city: string | null
    state: string | null
    email: string | null
    phone: string | null
    deleted: boolean
  }
  job?: { id: string; code: string; title: string; status: JobStatus; status_label: string; department: string | null }
  creator?: string | null
  history?: StageHistoryEntry[]
}

export interface JobHistoryEntry {
  id: number
  event: string
  event_label: string
  description: string | null
  reason: string | null
  user: string | null
  created_at: string
}

export interface JobListParams {
  search?: string
  statuses?: string[]
  department_id?: string
  professional_area?: string
  mine?: boolean
  pending_my_approval?: boolean
  sort?: string
  page?: number
  per_page?: number
}

const optionsCache = cachedUntilMutation(() => http.get<{ data: JobOptions }>('/v1/jobs/options').then((r) => r.data.data))

export const PIPELINE: Stage[] = ['applied', 'screening', 'shortlisted', 'interview', 'technical', 'approved', 'hired']
const OPEN = (s: Stage) => !['hired', 'rejected', 'withdrawn'].includes(s)

/** Mesma regra do backend (MoveApplication::requiredPermission): exige decisão do RH/gestor? */
export function needsDecision(from: Stage, to: Stage): boolean {
  if (to === 'approved' || to === 'hired' || !OPEN(from)) return true
  return to === 'rejected' && PIPELINE.indexOf(from) >= PIPELINE.indexOf('approved')
}

export function canMoveTo(from: Stage, to: Stage, abilities: { move: boolean; decide: boolean }): boolean {
  if (from === to) return false
  if (to === 'hired' && from !== 'approved') return false
  return needsDecision(from, to) ? abilities.decide : abilities.move
}

export const jobsApi = {
  async list(params: JobListParams): Promise<Paginated<JobListItem>> {
    const { statuses, mine, pending_my_approval, ...rest } = params
    const query: Record<string, unknown> = Object.fromEntries(Object.entries(rest).filter(([, v]) => v !== '' && v !== undefined && v !== null))
    if (statuses?.length) query.status = statuses
    if (mine) query.mine = 1
    if (pending_my_approval) query.pending_my_approval = 1
    const { data } = await http.get<Paginated<JobListItem>>('/v1/jobs', { params: query })
    return data
  },
  options(): Promise<JobOptions> {
    return optionsCache.get()
  },
  async get(id: string): Promise<Job> {
    const { data } = await http.get<{ data: Job }>(`/v1/jobs/${id}`)
    return data.data
  },
  async create(payload: JobPayload): Promise<Job> {
    const { data } = await http.post<{ data: Job }>('/v1/jobs', payload)
    return data.data
  },
  async update(id: string, payload: JobPayload): Promise<Job> {
    const { data } = await http.put<{ data: Job }>(`/v1/jobs/${id}`, payload)
    return data.data
  },
  async transition(id: string, action: JobAction, reason?: string): Promise<Job> {
    const { data } = await http.post<{ data: Job }>(`/v1/jobs/${id}/transition`, { action, reason: reason || null })
    return data.data
  },
  async history(id: string, page = 1): Promise<{ data: JobHistoryEntry[]; meta: { last_page: number } }> {
    const { data } = await http.get(`/v1/jobs/${id}/history`, { params: { page } })
    return data
  },
  async applications(id: string): Promise<{ data: ApplicationItem[]; meta: { counts: Record<Stage, number>; positions_count: number } }> {
    const { data } = await http.get(`/v1/jobs/${id}/applications`)
    return data
  },
  async addCandidates(id: string, candidates: string[]): Promise<{ data: ApplicationItem[]; meta: { added: number; already: string[]; inactive: string[] } }> {
    const { data } = await http.post(`/v1/jobs/${id}/applications`, { candidates })
    return data
  },
}

export const applicationsApi = {
  async get(id: string): Promise<ApplicationItem> {
    const { data } = await http.get<{ data: ApplicationItem }>(`/v1/applications/${id}`)
    return data.data
  },
  async move(id: string, stage: Stage, reason?: string | null, note?: string | null): Promise<{ data: ApplicationItem; meta: { job_closed: boolean } }> {
    const { data } = await http.post(`/v1/applications/${id}/move`, { stage, reason: reason || null, note: note || null })
    return data
  },
  async forCandidate(candidateId: string): Promise<ApplicationItem[]> {
    const { data } = await http.get<{ data: ApplicationItem[] }>(`/v1/candidates/${candidateId}/applications`)
    return data.data
  },
}

export const jobStatusClasses: Record<JobStatus, string> = {
  draft: 'bg-slate-100 text-slate-700 ring-slate-200',
  pending_approval: 'bg-amber-50 text-amber-800 ring-amber-200',
  rejected: 'bg-red-50 text-red-700 ring-red-200',
  approved: 'bg-sky-50 text-sky-700 ring-sky-200',
  published: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  on_hold: 'bg-orange-50 text-orange-700 ring-orange-200',
  closed: 'bg-slate-200 text-slate-700 ring-slate-300',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-200 line-through',
}

export const stageClasses: Record<Stage, string> = {
  applied: 'bg-slate-400',
  screening: 'bg-sky-400',
  shortlisted: 'bg-indigo-400',
  interview: 'bg-violet-500',
  technical: 'bg-fuchsia-500',
  approved: 'bg-emerald-500',
  hired: 'bg-emerald-700',
  rejected: 'bg-red-500',
  withdrawn: 'bg-slate-500',
}
