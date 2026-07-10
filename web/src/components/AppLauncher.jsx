import { useCallback, useEffect, useRef, useState } from 'react'
import {
  CheckCircle,
  ExternalLink,
  Flame,
  Hammer,
  LayoutGrid,
  Megaphone,
  Shield,
  Ticket,
  Users,
} from 'lucide-react'
import { useAuth } from '../lib/auth.jsx'
import * as controllerAccess from '../lib/controllerAccess.js'

const ICONS = {
  'layout-grid': LayoutGrid,
  shield: Shield,
  'check-circle': CheckCircle,
  flame: Flame,
  users: Users,
  megaphone: Megaphone,
  hammer: Hammer,
  ticket: Ticket,
}

function AppIcon({ name, size = 18 }) {
  const Icon = ICONS[name] || LayoutGrid
  return <Icon size={size} className="text-aicountly-600" aria-hidden />
}

export default function AppLauncher() {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  const [apps, setApps] = useState(user?.controller_apps ?? [])
  const [loading, setLoading] = useState(false)
  const [prefetching, setPrefetching] = useState(false)
  const [launchUrls, setLaunchUrls] = useState({})
  const [launchingCode, setLaunchingCode] = useState('')
  const [launchError, setLaunchError] = useState('')
  const ref = useRef(null)

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  useEffect(() => {
    if (user?.controller_apps?.length) {
      setApps(user.controller_apps)
    }
  }, [user?.controller_apps])

  const prefetchLaunchUrls = useCallback(async (appList) => {
    const targets = appList.filter(
      (app) => app?.code && !app.is_current && app.can_open !== false,
    )
    if (targets.length === 0) {
      setLaunchUrls({})
      return
    }

    setPrefetching(true)
    try {
      const entries = await Promise.all(
        targets.map(async (app) => {
          try {
            const data = await controllerAccess.getSsoLaunchUrl(app.code)
            return [app.code, data?.redirect_url || '']
          } catch {
            return [app.code, '']
          }
        }),
      )
      setLaunchUrls(Object.fromEntries(entries))
    } finally {
      setPrefetching(false)
    }
  }, [])

  const handleToggle = async () => {
    const next = !open
    setOpen(next)
    if (!next) {
      return
    }

    setLaunchError('')
    setLoading(true)
    try {
      const data = await controllerAccess.getLauncherApps()
      const nextApps = data?.apps ?? []
      setApps(nextApps)
      await prefetchLaunchUrls(nextApps)
    } catch {
      const fallbackApps = user?.controller_apps ?? []
      setApps(fallbackApps)
      if (fallbackApps.length > 0) {
        await prefetchLaunchUrls(fallbackApps)
      }
    } finally {
      setLoading(false)
    }
  }

  const openInNewTab = async (app) => {
    if (!app?.code || app.is_current || app.can_open === false) {
      return
    }

    setLaunchError('')
    setLaunchingCode(app.code)

    try {
      let redirectUrl = launchUrls[app.code]
      if (!redirectUrl) {
        const data = await controllerAccess.getSsoLaunchUrl(app.code)
        redirectUrl = data?.redirect_url
      }
      if (!redirectUrl) {
        throw new Error('Console did not return a launch URL.')
      }
      window.open(redirectUrl, '_blank', 'noopener,noreferrer')
      setOpen(false)
    } catch (err) {
      setLaunchError(err?.message || 'Could not open controller app.')
    } finally {
      setLaunchingCode('')
    }
  }

  const handleTileClick = (event, app) => {
    if (!app?.code || app.is_current || app.can_open === false) {
      setOpen(false)
      return
    }

    const redirectUrl = launchUrls[app.code]
    if (redirectUrl) {
      setOpen(false)
      return
    }

    event.preventDefault()
    openInNewTab(app)
  }

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-neutral-200 bg-white text-neutral-700 shadow-sm transition hover:border-neutral-300 hover:bg-neutral-50 hover:text-neutral-900"
        title="Top Controller Apps"
        aria-label="Top Controller Apps"
        onClick={handleToggle}
      >
        <LayoutGrid size={20} strokeWidth={2.25} />
      </button>

      {open && (
        <div className="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg">
          <div className="border-b border-neutral-100 px-4 py-3 text-sm font-semibold text-neutral-800">
            Top Controller Apps
          </div>

          {prefetching ? (
            <div className="mx-3 mt-3 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700">
              Preparing secure launch links…
            </div>
          ) : null}

          {launchError ? (
            <div className="mx-3 mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
              {launchError}
            </div>
          ) : null}

          {loading && <div className="px-4 py-6 text-center text-sm text-neutral-500">Loading apps…</div>}

          {!loading && apps.length === 0 && (
            <div className="px-4 py-6 text-center text-sm text-neutral-500">
              No controller apps assigned.
            </div>
          )}

          {!loading && apps.length > 0 && (
            <div className="grid max-h-[420px] grid-cols-2 gap-2 overflow-y-auto p-3">
              {apps.map((app) => {
                const redirectUrl = launchUrls[app.code]
                const isLocked = app.can_open === false && !app.is_current
                const isLaunchable = Boolean(app.code && !app.is_current && !isLocked)
                const TileTag = isLaunchable && redirectUrl ? 'a' : 'button'
                const tileProps =
                  isLaunchable && redirectUrl
                    ? {
                        href: redirectUrl,
                        target: '_blank',
                        rel: 'noopener noreferrer',
                        onClick: (event) => handleTileClick(event, app),
                      }
                    : {
                        type: 'button',
                        disabled: Boolean(launchingCode) || app.is_current || isLocked,
                        onClick: () => openInNewTab(app),
                      }

                return (
                  <TileTag
                    key={app.code}
                    {...tileProps}
                    className={[
                      'flex flex-col items-start gap-1.5 rounded-lg border p-3 text-left transition',
                      app.is_current
                        ? 'cursor-default border-aicountly-300 bg-aicountly-50'
                        : isLocked
                          ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-75'
                          : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50',
                      launchingCode && launchingCode !== app.code ? 'opacity-60' : '',
                    ].join(' ')}
                  >
                    <AppIcon name={app.icon} />
                    <p className="m-0 text-sm font-semibold text-neutral-900">
                      {app.name}
                      {!app.is_current && app.base_url ? (
                        <ExternalLink size={12} className="ml-1 inline align-middle text-neutral-400" />
                      ) : null}
                    </p>
                    {app.subtitle ? (
                      <p className="m-0 text-xs leading-snug text-neutral-500">{app.subtitle}</p>
                    ) : null}
                    {launchingCode === app.code ? (
                      <p className="m-0 text-xs text-aicountly-700">Opening…</p>
                    ) : null}
                    {app.is_current ? (
                      <p className="m-0 text-xs font-medium text-aicountly-700">Current app</p>
                    ) : null}
                    {isLocked ? (
                      <p className="m-0 text-xs text-neutral-400">No access</p>
                    ) : null}
                  </TileTag>
                )
              })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
