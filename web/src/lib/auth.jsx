import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { tokenStore } from './api.js'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => tokenStore.user)
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  useEffect(() => {
    if (!tokenStore.access) return
    setLoading(true)
    api.get('/v1/me').then((res) => {
      const u = res.data?.data?.user || res.data?.user
      if (u) {
        setUser(u)
        tokenStore.set({ user: u })
      }
    }).catch(() => {}).finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email, password) => {
    setLoading(true)
    try {
      const res = await api.post('/v1/auth/login', { email, password })
      const payload = res.data?.data || res.data
      tokenStore.set({
        access: payload.access_token,
        refresh: payload.refresh_token,
        user: payload.user,
      })
      setUser(payload.user)
      return payload.user
    } finally {
      setLoading(false)
    }
  }, [])

  const logout = useCallback(async () => {
    try { await api.post('/v1/auth/logout') } catch {}
    tokenStore.clear()
    setUser(null)
    navigate('/login', { replace: true })
  }, [navigate])

  const value = useMemo(() => ({
    user,
    loading,
    isAuthenticated: !!user,
    hasRole: (r) => Array.isArray(user?.roles) && user.roles.includes(r),
    login,
    logout,
    setUser,
  }), [user, loading, login, logout])

  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthCtx)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
