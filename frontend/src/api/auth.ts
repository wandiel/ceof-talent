import { ensureCsrfCookie, http } from './http'
import type { User } from './types'

export async function login(email: string, password: string, remember: boolean): Promise<User> {
  await ensureCsrfCookie()
  const { data } = await http.post<{ data: User }>('/v1/auth/login', { email, password, remember })
  return data.data
}

export async function fetchMe(): Promise<User> {
  const { data } = await http.get<{ data: User }>('/v1/auth/me')
  return data.data
}

export async function logout(): Promise<void> {
  await http.post('/v1/auth/logout')
}

export async function changePassword(payload: { current_password: string; password: string; password_confirmation: string }) {
  const { data } = await http.put<{ message: string }>('/v1/auth/password', payload)
  return data.message
}

export async function forgotPassword(email: string): Promise<string> {
  await ensureCsrfCookie()
  const { data } = await http.post<{ message: string }>('/v1/auth/forgot-password', { email })
  return data.message
}

export async function resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }) {
  await ensureCsrfCookie()
  const { data } = await http.post<{ message: string }>('/v1/auth/reset-password', payload)
  return data.message
}
