import axios, { AxiosError, type AxiosRequestConfig } from 'axios'

/**
 * Cliente HTTP único da aplicação. Usa caminho relativo (/api) porque o
 * frontend e a API são servidos pelo mesmo Nginx (mesma origem): a sessão
 * fica em cookie HttpOnly e o token CSRF é enviado automaticamente pelo
 * axios a partir do cookie XSRF-TOKEN (Laravel Sanctum).
 */
export const http = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  timeout: 20000,
})

/** Obtém o cookie CSRF do Sanctum (necessário antes de login e formulários públicos). */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

type RetriableConfig = AxiosRequestConfig & { _csrfRetried?: boolean }

let unauthorizedHandler: (() => void) | null = null
let passwordChangeHandler: (() => void) | null = null

export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler
}

export function onPasswordChangeRequired(handler: () => void) {
  passwordChangeHandler = handler
}

http.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<{ code?: string }>) => {
    const status = error.response?.status
    const config = error.config as RetriableConfig | undefined

    // 419: token CSRF expirado. Renova o cookie e repete a requisição uma vez.
    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await ensureCsrfCookie()
      return http.request(config)
    }

    if (status === 401 && !config?.url?.endsWith('/auth/me')) {
      unauthorizedHandler?.()
    }

    if (status === 403 && error.response?.data?.code === 'password_change_required') {
      passwordChangeHandler?.()
    }

    return Promise.reject(error)
  },
)

export interface ApiError {
  status: number | null
  message: string
  fields: Record<string, string>
}

/** Normaliza erros da API em mensagem geral + primeiro erro de cada campo. */
export function parseApiError(error: unknown): ApiError {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status ?? null
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const fields: Record<string, string> = {}

    for (const [key, messages] of Object.entries(data?.errors ?? {})) {
      if (messages?.length) fields[key] = messages[0] as string
    }

    let message = data?.message ?? 'Não foi possível concluir a operação.'
    if (status === null) message = 'Sem conexão com o servidor. Verifique sua rede e tente novamente.'
    else if (status === 403) message = data?.message && data.message !== 'This action is unauthorized.' ? data.message : 'Você não tem permissão para esta ação.'
    else if (status === 404) message = 'Registro não encontrado.'
    else if (status === 422 || status === 409 || status === 429) message = Object.values(fields)[0] ?? message
    else if (status >= 500) message = 'Erro interno do servidor. Tente novamente em instantes.'

    return { status, message, fields }
  }

  return { status: null, message: 'Erro inesperado.', fields: {} }
}
