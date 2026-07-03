import { useEffect, useState } from 'react'
import { useAuth } from '../lib/auth.jsx'
import api from '../lib/api.js'
import { BotModeBadge } from './Badges.jsx'

export default function Topbar({ onOpenMenu }) {
  const { user, logout } = useAuth()
  const [mode, setMode] = useState(null)

  useEffect(() => {
    let cancelled = false
    api.get('/v1/bot/settings').then((r) => {
      if (cancelled) return
      const d = r.data?.data || r.data
      setMode(d?.bot_mode || d?.mode || 'confirm')
    }).catch(() => {})
    return () => { cancelled = true }
  }, [])

  return (
    <header className="sticky top-0 z-20 flex items-center justify-between border-b border-neutral-200 bg-white/95 backdrop-blur px-4 lg:px-6 h-14">
      <div className="flex items-center gap-3">
        <button
          className="lg:hidden inline-flex items-center justify-center h-9 w-9 rounded-md border border-neutral-200 text-neutral-700 hover:bg-neutral-50"
          onClick={onOpenMenu}
          aria-label="Open menu"
        >
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fillRule="evenodd" d="M3 5h14a1 1 0 010 2H3a1 1 0 010-2zm0 4h14a1 1 0 010 2H3a1 1 0 010-2zm0 4h14a1 1 0 010 2H3a1 1 0 010-2z" clipRule="evenodd" />
          </svg>
        </button>
        <div className="text-sm text-neutral-500">Internal sales & bot control</div>
      </div>
      <div className="flex items-center gap-3">
        {mode ? <BotModeBadge mode={mode} /> : null}
        {user ? (
          <>
            <div className="hidden sm:block text-right leading-tight">
              <div className="text-sm font-medium text-neutral-800">{user.name || user.email}</div>
              <div className="text-[11px] text-neutral-500">{(user.roles || []).join(', ') || '—'}</div>
            </div>
            <div className="h-9 w-9 rounded-full bg-aicountly-600 text-white flex items-center justify-center text-xs font-semibold">
              {(user.name || user.email || '?').slice(0, 2).toUpperCase()}
            </div>
            <button className="engage-btn-secondary text-xs" onClick={logout}>Sign out</button>
          </>
        ) : null}
      </div>
    </header>
  )
}
