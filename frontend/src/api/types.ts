export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export interface Ref {
  id: string
  name: string
}

export type RoleName = 'admin' | 'hr_manager' | 'hr_analyst' | 'recruiter' | 'department_manager' | 'interviewer'

export interface UserRole {
  name: RoleName
  label: string
}

export interface User {
  id: string
  name: string
  email: string
  job_title: string | null
  phone: string | null
  is_active: boolean
  must_change_password: boolean
  unit: Ref | null
  department: Ref | null
  roles: UserRole[]
  permissions?: string[]
  last_login_at: string | null
  created_at: string
  updated_at: string
}

export interface RoleDefinition {
  name: RoleName
  label: string
  description: string
  permissions: { name: string; label: string; group: string }[]
}

export interface Unit {
  id: string
  name: string
  code: string | null
  city: string | null
  state: string | null
  is_active: boolean
  users_count?: number
}

export interface Department {
  id: string
  name: string
  code: string | null
  description: string | null
  is_active: boolean
  positions_count?: number
  users_count?: number
}

export interface Position {
  id: string
  title: string
  cbo_code: string | null
  description: string | null
  is_active: boolean
  department: Ref | null
}

export interface ListParams {
  search?: string
  status?: '' | 'active' | 'inactive'
  sort?: string
  page?: number
  per_page?: number
  [key: string]: string | number | undefined
}
