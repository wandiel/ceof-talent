import axios from 'axios'

/**
 * Cliente HTTP único da aplicação. Usa caminho relativo (/api) porque o
 * frontend e a API são servidos pelo mesmo Nginx (mesma origem), o que
 * simplifica cookies de sessão (Sanctum) e evita CORS.
 */
export const http = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
  timeout: 20000,
})
