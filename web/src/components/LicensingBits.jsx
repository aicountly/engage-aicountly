import clsx from 'clsx'
import { TONE_CLASSES, formatDateIN } from '../lib/licensing.js'

/** Licence status pill — label and tone come from the API. */
export function LicenceStatusBadge({ status }) {
  if (!status) return null
  return <span className={clsx('engage-pill', TONE_CLASSES[status.tone] || TONE_CLASSES.neutral)}>{status.label || status.code}</span>
}

const OFFER_TONES = { draft: 'warning', published: 'success', retired: 'neutral' }
export function OfferStatusBadge({ status }) {
  const label = status ? status.charAt(0).toUpperCase() + status.slice(1) : ''
  return <span className={clsx('engage-pill', TONE_CLASSES[OFFER_TONES[status]] || TONE_CLASSES.neutral)}>{label}</span>
}

/** Licence activity (engage_licence_events), newest first. */
export function LicenceTimeline({ events = [], showLicence = false }) {
  if (!events.length) return <div className="text-sm text-neutral-500">No activity yet.</div>
  return (
    <ol className="border-l border-neutral-200 pl-4 space-y-3">
      {events.map((e) => (
        <li key={e.event_id} className="relative">
          <span className="absolute -left-[9px] top-1 h-3 w-3 rounded-full bg-aicountly-500 border-2 border-white" />
          <div className="text-xs text-neutral-500">
            {formatDateIN(e.occurred_at, { withTime: true })} · #{e.seq} · {e.actor?.display || e.actor?.kind}
            {showLicence ? <> · <span className="font-mono">{e.licence_ref}</span></> : null}
          </div>
          <div className="text-sm font-medium text-neutral-900">{e.type?.label || e.type?.code}</div>
          <div className="text-sm text-neutral-700">{e.summary}</div>
          {e.reason ? <div className="text-xs text-neutral-500 mt-0.5">Reason (internal): {e.reason}</div> : null}
        </li>
      ))}
    </ol>
  )
}

export function ErrorNote({ error }) {
  if (!error) return null
  return <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 whitespace-pre-wrap">{error}</div>
}

/** apiError plus field details (422) on separate lines. */
export function describeError(err, fallback) {
  const d = err?.response?.data
  const main = d?.error || err?.message || fallback || 'Something went wrong.'
  const details = d?.details && typeof d.details === 'object'
    ? Object.entries(d.details).map(([k, v]) => `• ${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`).join('\n')
    : ''
  return details ? `${main}\n${details}` : String(main)
}
