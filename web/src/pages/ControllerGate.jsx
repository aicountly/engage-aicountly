import {
  GATE_CONSOLE_REQUIRED,
  GATE_ERROR,
  GATE_NO_ACCESS,
  useAuth,
} from '../lib/auth.jsx'

const CONSOLE_URL = (import.meta.env.VITE_CONSOLE_URL || 'https://console.aicountly.org').replace(/\/$/, '')
const APP_NAME = import.meta.env.VITE_APP_NAME || 'AICOUNTLY Engage Portal'

function consoleLoginUrl() {
  const returnUrl = encodeURIComponent(window.location.origin + '/')
  return `${CONSOLE_URL}/login?return=${returnUrl}`
}

export default function ControllerGate() {
  const { gateReason, gateMessage, retryAuth, ssoPending } = useAuth()

  const reason = gateReason || GATE_CONSOLE_REQUIRED
  const isPending = ssoPending

  return (
    <div className="grid h-screen w-screen place-items-center bg-gradient-to-br from-white to-aicountly-50 px-4">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-aicountly-600 text-sm font-bold text-white">
            EN
          </span>
          <div>
            <div className="text-base font-semibold text-neutral-900">{APP_NAME}</div>
            <div className="text-xs text-neutral-500">engage.aicountly.org · Console identity only</div>
          </div>
        </div>

        <div className="engage-card">
          {isPending ? (
            <>
              <h1 className="text-lg font-semibold text-neutral-900">Signing you in…</h1>
              <p className="mt-2 text-sm text-neutral-600">Checking your Console session and controller access.</p>
            </>
          ) : reason === GATE_NO_ACCESS ? (
            <>
              <h1 className="text-lg font-semibold text-amber-800">Access not assigned</h1>
              <p className="mt-2 text-sm text-neutral-600">
                You are signed in to Console, but this account does not have access to the Engage controller app.
              </p>
              {gateMessage ? (
                <div className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">{gateMessage}</div>
              ) : null}
              <p className="mt-3 text-xs text-neutral-500">
                Ask a Console administrator to grant Engage access under Controller App Access, then click Retry.
              </p>
            </>
          ) : reason === GATE_ERROR ? (
            <>
              <h1 className="text-lg font-semibold text-red-700">Sign-in failed</h1>
              <p className="mt-2 text-sm text-neutral-600">
                {gateMessage || 'Could not complete Console sign-in for Engage Portal.'}
              </p>
            </>
          ) : (
            <>
              <h1 className="text-lg font-semibold text-neutral-900">Sign in via Console</h1>
              <p className="mt-2 text-sm text-neutral-600">
                This portal does not use a local email/password login. Sign in at{' '}
                <strong>console.aicountly.org</strong>, then open Engage from Top Controller Apps or return here.
              </p>
              {gateMessage && gateMessage !== 'Sign in to Console first.' ? (
                <div className="mt-3 rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-700">{gateMessage}</div>
              ) : null}
            </>
          )}

          <div className="mt-5 flex flex-col gap-2 sm:flex-row">
            {reason === GATE_CONSOLE_REQUIRED ? (
              <a href={consoleLoginUrl()} className="engage-btn-primary justify-center text-center">
                Open Console sign-in
              </a>
            ) : null}
            <button
              type="button"
              className="engage-btn-secondary justify-center"
              onClick={() => retryAuth()}
              disabled={isPending}
            >
              {isPending ? 'Checking…' : 'Retry'}
            </button>
          </div>
        </div>

        <p className="mt-4 text-center text-[11px] text-neutral-400">
          Sales &amp; engagement operations · superadmin access only
        </p>
      </div>
    </div>
  )
}
