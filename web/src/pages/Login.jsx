import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/auth.jsx'
import { apiError } from '../lib/api.js'

export default function Login() {
  const { login, isAuthenticated } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [err, setErr] = useState(null)
  const location = useLocation()
  const navigate = useNavigate()

  if (isAuthenticated) {
    return <Navigate to={location.state?.from?.pathname || '/'} replace />
  }

  async function onSubmit(e) {
    e.preventDefault()
    setErr(null)
    setSubmitting(true)
    try {
      await login(email.trim(), password)
      navigate(location.state?.from?.pathname || '/', { replace: true })
    } catch (e) {
      setErr(apiError(e))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen w-full bg-gradient-to-br from-aicountly-50 via-white to-aicountly-100 flex items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="flex items-center gap-3 justify-center mb-6">
          <div className="h-11 w-11 rounded-xl bg-aicountly-600 flex items-center justify-center text-white font-bold shadow-sm">EN</div>
          <div className="text-left">
            <div className="text-lg font-semibold text-neutral-900 leading-tight">AICOUNTLY</div>
            <div className="text-sm text-aicountly-700 font-medium leading-tight">Engage portal</div>
          </div>
        </div>
        <div className="engage-card">
          <h1 className="text-xl font-semibold text-neutral-900">Sign in</h1>
          <p className="text-sm text-neutral-500 mt-1">Superadmin access only. All actions are audited.</p>

          <form className="mt-5 space-y-4" onSubmit={onSubmit}>
            <div>
              <label className="engage-label" htmlFor="email">Work email</label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                required
                className="engage-input"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@aicountly.com"
              />
            </div>
            <div>
              <label className="engage-label" htmlFor="password">Password</label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                className="engage-input"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
            {err ? (
              <div className="rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">
                {err}
              </div>
            ) : null}
            <button type="submit" disabled={submitting} className="engage-btn-primary w-full">
              {submitting ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
        </div>
        <div className="text-center text-xs text-neutral-400 mt-6">
          engage.aicountly.org · Internal sales control centre
        </div>
      </div>
    </div>
  )
}
