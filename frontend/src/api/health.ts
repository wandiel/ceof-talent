import { http } from './http'

export type CheckStatus = 'ok' | 'fail'

export interface HealthCheck {
  status: CheckStatus
  duration_ms: number
  driver?: string
  error?: string
}

export interface HealthResponse {
  service: string
  environment: string
  time: string
  status: CheckStatus
  checks: Record<string, HealthCheck>
}

export async function fetchHealth(): Promise<HealthResponse> {
  // 503 também traz o corpo com o detalhe de cada componente.
  const response = await http.get<HealthResponse>('/health', {
    validateStatus: (status) => status === 200 || status === 503,
  })
  return response.data
}
