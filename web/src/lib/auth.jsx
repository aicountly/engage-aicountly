import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import api, { getToken, setToken, v1 } from './api.js'
import { clearControllerSsoHash, readControllerSsoToken } from './controllerSso.js'
import { redirectToConsoleLogin } from './consoleAuth.js'

const AuthCtx = createContext(null)

export const GATE_CONSOLE_REQUIRED = 'console_required'
export const GATE_NO_ACCESS = 'no_access'
export const GATE_ERROR = 'error'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [ssoPending, setSsoPending] = useState(false)
  const [gateReason, setGateReason] = useState(null)
  const [gateMessage, setGateMessage] = useState('')

  const applySession = useCallback((data) => {
    const token = data?.data?.token
    if (!token) throw new Error('Session succeeded but no token was returned')
    setToken(token)
    setUser(data.data.user)
    setGateReason(null)
    setGateMessage('')
    return data.data.user
  }, [])

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setUser(null)
      return false
    }
    try {
      const { data } = await api.get(v1('/me'))
      setUser(data?.data ?? null)
      setGateReason(null)
      setGateMessage('')
      return true
    } catch {
      setToken('')
      setUser(null)
      return false
    }
  }, [])

  const loginWithControllerSso = useCallback(async (ssoToken) => {
    const { data } = await api.post(v1('/auth/controller-sso'), { token: ssoToken })
    if (!data?.ok) throw new Error(data?.error || 'Controller SSO failed')
    return applySession(data)
  }, [applySession])

  const loginWithConsoleSession = useCallback(async () => {
    const { data } = await api.post(v1('/auth/console-session'), {}, { withCredentials: true })
    if (!data?.ok) throw new Error(data?.error || 'Console session sign-in failed')
    return applySession(data)
  }, [applySession])

  const bootstrap = useCallback(async () => {
    setLoading(true)
    setGateReason(null)
    setGateMessage('')

    const ssoToken = readControllerSsoToken()
    if (ssoToken) {
      clearControllerSsoHash()
      setSsoPending(true)
      try {
        await loginWithControllerSso(ssoToken)
      } catch (e) {
        setGateReason(GATE_ERROR)
        setGateMessage(e?.response?.data?.error || e.message || 'Console SSO login failed')
      } finally {
        setSsoPending(false)
        setLoading(false)
      }
      return
    }

    if (getToken()) {
      const ok = await refresh()
      if (ok) {
        setLoading(false)
        return
      }
    }

    setSsoPending(true)
    try {
      await loginWithConsoleSession()
    } catch (e) {
      const status = e?.response?.status
      const message = e?.response?.data?.error || e.message || 'Could not sign in via Console'
      if (status === 401) {
        redirectToConsoleLogin()
        return
      } else if (status === 403) {
        setGateReason(GATE_NO_ACCESS)
        setGateMessage(message)
      } else {
        setGateReason(GATE_ERROR)
        setGateMessage(message)
      }
    } finally {
      setSsoPending(false)
      setLoading(false)
    }
  }, [loginWithConsoleSession, loginWithControllerSso, refresh])

  useEffect(() => {
    bootstrap()
  }, [bootstrap])

  const retryAuth = useCallback(async () => {
    setToken('')
    setUser(null)
    await bootstrap()
  }, [bootstrap])

  const logout = useCallback(async () => {
    try {
      await api.post(v1('/auth/logout'))
    } catch {
      /* ignore */
    }
    setToken('')
    setUser(null)
    redirectToConsoleLogin()
  }, [])

  const hasRole = useCallback(
    (roles) => {
      if (!user) return false
      const allowed = Array.isArray(roles) ? roles : [roles]
      return allowed.some((r) => (user.roles || []).includes(r))
    },
    [user],
  )

  return (
    <AuthCtx.Provider
      value={{
        user,
        loading,
        ssoPending,
        gateReason,
        gateMessage,
        loginWithControllerSso,
        loginWithConsoleSession,
        logout,
        refresh,
        retryAuth,
        hasRole,
      }}
    >
      {children}
    </AuthCtx.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthCtx)
  if (!ctx) throw new Error('useAuth must be inside <AuthProvider>')
  return ctx
}
