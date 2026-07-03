import { formatMoney, formatDate } from '../lib/format.js'
import { StatusBadge } from './Badges.jsx'

export default function ProposalPanel({ proposals = [], onOpen }) {
  if (!proposals.length) {
    return <div className="text-sm text-neutral-500">No proposals yet.</div>
  }
  return (
    <div className="space-y-2">
      {proposals.map((p) => (
        <div key={p.id} className="engage-card p-3 flex items-start justify-between gap-3 cursor-pointer hover:border-aicountly-400" onClick={() => onOpen?.(p)}>
          <div>
            <div className="text-sm font-semibold text-neutral-900">{p.code || `#${p.id}`}</div>
            <div className="text-xs text-neutral-500">Issued {formatDate(p.created_at)} · Valid until {formatDate(p.valid_until)}</div>
            <div className="text-sm text-neutral-700 mt-1">{p.title || p.summary || '—'}</div>
          </div>
          <div className="text-right">
            <div className="text-sm font-semibold text-neutral-900">{formatMoney(p.net_amount ?? p.total_amount, p.currency)}</div>
            <StatusBadge status={p.status} />
          </div>
        </div>
      ))}
    </div>
  )
}
