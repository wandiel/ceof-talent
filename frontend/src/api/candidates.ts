import { http } from './http'
import type { ListParams, Paginated } from './types'

export interface Option {
  value: string
  label: string
}

export interface CandidateOptions {
  statuses: Option[]
  sources: Option[]
  professional_areas: Option[]
  education_levels: Option[]
  education_statuses: Option[]
  availabilities: Option[]
  work_shifts: Option[]
  course_types: Option[]
  skill_levels: Option[]
  document_types: Option[]
  states: string[]
}

export interface CandidateListItem {
  id: string
  full_name: string
  social_name: string | null
  email: string | null
  phone: string | null
  city: string | null
  state: string | null
  professional_area: string
  professional_area_label: string
  education_level: string
  education_level_label: string
  desired_position: string | null
  source: string
  source_label: string
  status: 'active' | 'inactive'
  documents_count?: number
  created_at: string
  updated_at: string
}

export interface Education {
  level: string
  level_label?: string
  institution: string
  course: string | null
  status: string
  status_label?: string
  start_year: number | null
  end_year: number | null
}

export interface Experience {
  company: string
  job_title: string
  start_date: string
  end_date: string | null
  is_current: boolean
  description: string | null
}

export interface Course {
  type: string
  type_label?: string
  name: string
  institution: string | null
  year: number | null
  workload_hours: number | null
  credential: string | null
}

export interface CandidateSkill {
  name: string
  level: string | null
}

export interface CandidateDocument {
  id: string
  type: string
  type_label: string
  original_name: string
  mime_type: string
  size: number
  uploaded_by?: string | null
  created_at: string
}

export interface CandidateNote {
  id: string
  body: string
  author?: string | null
  created_at: string
}

export interface Candidate extends CandidateListItem {
  phone_secondary: string | null
  cpf: string | null
  cpf_masked: string | null
  birth_date: string | null
  age: number | null
  linkedin_url: string | null
  portfolio_url: string | null
  zip_code: string | null
  neighborhood: string | null
  summary: string | null
  salary_expectation: string | null
  availability: string | null
  availability_label: string | null
  work_shifts: string[]
  work_shifts_labels: string[]
  willing_to_relocate: boolean
  source_detail: string | null
  educations: Education[]
  experiences: Experience[]
  courses: Course[]
  skills: CandidateSkill[]
  documents: CandidateDocument[]
  notes: CandidateNote[]
  created_by?: string | null
  updated_by?: string | null
  abilities: { update: boolean; delete: boolean; download_documents: boolean }
}

export interface CandidatePayload {
  full_name: string
  social_name: string | null
  email: string | null
  phone: string | null
  phone_secondary: string | null
  cpf: string | null
  birth_date: string | null
  linkedin_url: string | null
  portfolio_url: string | null
  zip_code: string | null
  neighborhood: string | null
  city: string | null
  state: string | null
  professional_area: string
  education_level: string
  desired_position: string | null
  summary: string | null
  salary_expectation: string | number | null
  availability: string | null
  work_shifts: string[]
  willing_to_relocate: boolean
  source: string
  source_detail: string | null
  educations: Education[]
  experiences: Experience[]
  courses: Course[]
  skills: CandidateSkill[]
}

export interface HistoryEntry {
  id: number
  event: string
  event_label: string
  description: string | null
  user: string | null
  created_at: string
}

function clean(params: ListParams): ListParams {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null)) as ListParams
}

let optionsCache: Promise<CandidateOptions> | null = null

export const candidatesApi = {
  async list(params: ListParams = {}): Promise<Paginated<CandidateListItem>> {
    const { data } = await http.get<Paginated<CandidateListItem>>('/v1/candidates', { params: clean(params) })
    return data
  },
  options(): Promise<CandidateOptions> {
    optionsCache ??= http.get<{ data: CandidateOptions }>('/v1/candidates/options').then((r) => r.data.data)
    optionsCache.catch(() => (optionsCache = null))
    return optionsCache
  },
  async get(id: string): Promise<Candidate> {
    const { data } = await http.get<{ data: Candidate }>(`/v1/candidates/${id}`)
    return data.data
  },
  async create(payload: CandidatePayload): Promise<Candidate> {
    const { data } = await http.post<{ data: Candidate }>('/v1/candidates', payload)
    return data.data
  },
  async update(id: string, payload: CandidatePayload): Promise<Candidate> {
    const { data } = await http.put<{ data: Candidate }>(`/v1/candidates/${id}`, payload)
    return data.data
  },
  async setStatus(id: string, status: 'active' | 'inactive'): Promise<Candidate> {
    const { data } = await http.patch<{ data: Candidate }>(`/v1/candidates/${id}/status`, { status })
    return data.data
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/v1/candidates/${id}`)
  },
  async history(id: string, page = 1, includeViews = false): Promise<{ data: HistoryEntry[]; meta: { current_page: number; last_page: number; total: number } }> {
    const { data } = await http.get(`/v1/candidates/${id}/history`, { params: { page, include_views: includeViews ? 1 : 0 } })
    return data
  },
  async addNote(id: string, body: string): Promise<CandidateNote> {
    const { data } = await http.post<{ data: CandidateNote }>(`/v1/candidates/${id}/notes`, { body })
    return data.data
  },
  async uploadDocument(id: string, type: string, file: File, onProgress?: (percent: number) => void): Promise<CandidateDocument> {
    const form = new FormData()
    form.append('type', type)
    form.append('file', file)
    const { data } = await http.post<{ data: CandidateDocument }>(`/v1/candidates/${id}/documents`, form, {
      timeout: 120000,
      onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : 0),
    })
    return data.data
  },
  documentUrl(candidateId: string, documentId: string): string {
    return `/api/v1/candidates/${candidateId}/documents/${documentId}`
  },
  async removeDocument(candidateId: string, documentId: string): Promise<void> {
    await http.delete(`/v1/candidates/${candidateId}/documents/${documentId}`)
  },
  async skills(search: string): Promise<{ name: string; candidates_count: number }[]> {
    const { data } = await http.get('/v1/skills', { params: { search } })
    return data.data
  },
}
