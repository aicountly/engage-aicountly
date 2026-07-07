import axios from 'axios'
import { resolveApiBaseUrl } from './apiBaseUrl'

const baseURL = resolveApiBaseUrl(import.meta.env.VITE_API_URL)

const api = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 45_000,
  withCredentials: true,
})

const TOKEN_KEY = 'engage.access_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

api.interceptors.request.use((config) => {
  const t = getToken()
  if (t) {
    if (typeof config.headers?.set === 'function') {
      config.headers.set('Authorization', `Bearer ${t}`)
    } else {
      config.headers.Authorization = `Bearer ${t}`
    }
  }
  return config
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      const hadToken = getToken()
      setToken('')
      // App-level ControllerGate handles unauthenticated state; avoid hard redirects.
      if (hadToken) {
        window.location.assign('/')
      }
    }
    return Promise.reject(err)
  },
)

export const v1 = (path) => `/v1${path.startsWith('/') ? path : `/${path}`}`

/**
 * Normalise different backend response shapes (rows/items/plain array).
 */
export function pickRows(res) {
  if (!res) return []
  const d = res.data?.data ?? res.data ?? res
  if (Array.isArray(d)) return d
  return d?.rows || d?.items || d?.results || []
}

export function apiError(err) {
  return err?.response?.data?.error?.message
      || err?.response?.data?.error
      || err?.response?.data?.message
      || err?.message
      || 'Something went wrong.'
}

export default api
