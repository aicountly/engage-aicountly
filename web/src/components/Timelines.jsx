import { formatDate, truncate, titleCase } from '../lib/format.js'
import { ApprovalBadge, StatusBadge } from './Badges.jsx'

export function LeadActivityTimeline({ items = [] }) {
  if (!items.length) return <div className="text-sm text-neutral-500">No activity yet.</div>
  return (
    <ol className="border-l border-neutral-200 pl-4 space-y-3">
      {items.map((it) => (
        <li key={it.id} className="relative">
          <span className="absolute -left-[9px] top-1 h-3 w-3 rounded-full bg-aicountly-500 border-2 border-white" />
          <div className="text-xs text-neutral-500">{formatDate(it.created_at, { withTime: true })} · {titleCase(it.author_kind || 'user')}</div>
          <div className="text-sm font-medium text-neutral-900">{titleCase(it.activity_type)}</div>
          {it.summary ? <div className="text-sm text-neutral-700 whitespace-pre-wrap">{it.summary}</div> : null}
        </li>
      ))}
    </ol>
  )
}

export function FollowUpTimeline({ items = [] }) {
  if (!items.length) return <div className="text-sm text-neutral-500">No follow-ups scheduled.</div>
  return (
    <ol className="space-y-2">
      {items.map((f) => (
        <li key={f.id} className="rounded-md border border-neutral-200 bg-white p-3 flex items-start justify-between gap-3">
          <div>
            <div className="text-sm font-medium text-neutral-900">{titleCase(f.channel || 'follow_up')} · {formatDate(f.due_at, { withTime: true })}</div>
            <div className="text-sm text-neutral-700">{f.subject || f.notes || '—'}</div>
          </div>
          <StatusBadge status={f.status} />
        </li>
      ))}
    </ol>
  )
}

export function BotReportTimeline({ items = [] }) {
  if (!items.length) return <div className="text-sm text-neutral-500">No bot reports yet.</div>
  return (
    <ol className="space-y-3">
      {items.map((r) => (
        <li key={r.id} className="rounded-md border border-neutral-200 bg-white p-3">
          <div className="flex items-center justify-between gap-2">
            <div>
              <div className="text-xs text-neutral-500">{formatDate(r.created_at, { withTime: true })}</div>
              <div className="text-sm font-semibold text-neutral-900">{titleCase(r.action_code || r.action_type)}</div>
            </div>
            <ApprovalBadge status={r.approval_status || 'not_required'} />
          </div>
          {r.recommendation ? (
            <div className="mt-2 text-sm text-neutral-700"><span className="font-medium">Recommendation:</span> {truncate(r.recommendation, 220)}</div>
          ) : null}
          {r.next_recommended_action ? (
            <div className="mt-1 text-sm text-neutral-600"><span className="font-medium">Next:</span> {truncate(r.next_recommended_action, 180)}</div>
          ) : null}
          {r.error_message ? (
            <div className="mt-2 text-sm text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1">Error: {r.error_message}</div>
          ) : null}
        </li>
      ))}
    </ol>
  )
}
