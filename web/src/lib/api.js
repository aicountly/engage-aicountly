import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL || '/api'

const api = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
  },
  timeout: 45_000,
})

const TOKEN_KEY  = 'engage.access_token'
const REFRESH_KEY = 'engage.refresh_token'
const USER_KEY    = 'engage.user'

export const tokenStore = {
  get access() { return localStorage.getItem(TOKEN_KEY) || null },
  get refresh() { return localStorage.getItem(REFRESH_KEY) || null },
  get user() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null') } catch { return null }
  },
  set({ access, refresh, user }) {
    if (access) localStorage.setItem(TOKEN_KEY, access)
    if (refresh) localStorage.setItem(REFRESH_KEY, refresh)
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
  },
  clear() {
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(REFRESH_KEY)
    localStorage.removeItem(USER_KEY)
  },
}

api.interceptors.request.use((config) => {
  const t = tokenStore.access
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

let refreshing = null
api.interceptors.response.use(
  (res) => res,
  async (err) => {
    const status = err.response?.status
    const original = err.config || {}
    if (status === 401 && !original._retry && tokenStore.refresh) {
      original._retry = true
      try {
        refreshing = refreshing || axios.post(`${baseURL}/v1/auth/refresh`, { refresh_token: tokenStore.refresh })
        const { data } = await refreshing
        refreshing = null
        const payload = data?.data || data
        tokenStore.set({ access: payload.access_token, refresh: payload.refresh_token })
        original.headers.Authorization = `Bearer ${payload.access_token}`
        return api(original)
      } catch (e) {
        refreshing = null
        tokenStore.clear()
        if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
          window.location.replace('/login')
        }
      }
    }
    return Promise.reject(err)
  }
)

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
