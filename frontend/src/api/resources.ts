import { http } from './http'
import type { Department, ListParams, Paginated, Position, RoleDefinition, Unit, User } from './types'

function cleanParams(params: ListParams): ListParams {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null)) as ListParams
}

/** Fábrica de funções CRUD para um recurso REST da API v1. */
function resource<T, Payload>(path: string) {
  return {
    async list(params: ListParams = {}): Promise<Paginated<T>> {
      const { data } = await http.get<Paginated<T>>(`/v1/${path}`, { params: cleanParams(params) })
      return data
    },
    async get(id: string): Promise<T> {
      const { data } = await http.get<{ data: T }>(`/v1/${path}/${id}`)
      return data.data
    },
    async create(payload: Payload): Promise<T> {
      const { data } = await http.post<{ data: T }>(`/v1/${path}`, payload)
      return data.data
    },
    async update(id: string, payload: Payload): Promise<T> {
      const { data } = await http.put<{ data: T }>(`/v1/${path}/${id}`, payload)
      return data.data
    },
    async remove(id: string): Promise<void> {
      await http.delete(`/v1/${path}/${id}`)
    },
    /** Todos os registros ativos, para campos de seleção. */
    async options(extra: ListParams = {}): Promise<T[]> {
      const { data } = await http.get<Paginated<T>>(`/v1/${path}`, { params: cleanParams({ status: 'active', per_page: 100, ...extra }) })
      return data.data
    },
  }
}

export interface UserPayload {
  name: string
  email: string
  job_title?: string | null
  phone?: string | null
  unit_id?: string | null
  department_id?: string | null
  roles: string[]
  is_active?: boolean
  password?: string
}

export const usersApi = {
  ...resource<User, UserPayload>('users'),
  async setStatus(id: string, isActive: boolean): Promise<User> {
    const { data } = await http.patch<{ data: User }>(`/v1/users/${id}/status`, { is_active: isActive })
    return data.data
  },
  async setTemporaryPassword(id: string, password: string): Promise<string> {
    const { data } = await http.put<{ message: string }>(`/v1/users/${id}/password`, { password })
    return data.message
  },
}

export async function fetchRoles(): Promise<RoleDefinition[]> {
  const { data } = await http.get<{ data: RoleDefinition[] }>('/v1/roles')
  return data.data
}

export type UnitPayload = Pick<Unit, 'name' | 'code' | 'city' | 'state' | 'is_active'>
export type DepartmentPayload = Pick<Department, 'name' | 'code' | 'description' | 'is_active'>
export interface PositionPayload {
  title: string
  department_id: string | null
  cbo_code: string | null
  description: string | null
  is_active: boolean
}

export const unitsApi = resource<Unit, UnitPayload>('units')
export const departmentsApi = resource<Department, DepartmentPayload>('departments')
export const positionsApi = resource<Position, PositionPayload>('positions')
