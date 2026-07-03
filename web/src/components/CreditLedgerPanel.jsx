import { formatDate, formatMoney, titleCase } from '../lib/format.js'
import { StatusBadge } from './Badges.jsx'

export default function CreditLedgerPanel({ entries = [], balance }) {
  return (
    <div className="engage-card">
      <div className="flex items-center justify-between mb-3">
        <div>
          <div className="text-xs uppercase tracking-wide text-neutral-500">Balance</div>
          <div className="text-lg font-semibold text-neutral-900">{formatMoney(balance?.amount ?? 0, balance?.currency)}</div>
        </div>
        <div className="text-xs text-neutral-500">{entries.length} entries</div>
      </div>
      {entries.length === 0 ? (
        <div className="text-sm text-neutral-500">No credit ledger entries.</div>
      ) : (
        <ol className="divide-y divide-neutral-100">
          {entries.map((e) => (
            <li key={e.id} className="py-2 flex items-start justify-between gap-3">
              <div>
                <div className="text-sm font-medium text-neutral-900">
                  {titleCase(e.credit_type || '')} · {titleCase(e.direction || '')}
                </div>
                <div className="text-xs text-neutral-500">{formatDate(e.created_at, { withTime: true })} · {e.source || '—'}</div>
                {e.remarks ? <div className="text-sm text-neutral-700">{e.remarks}</div> : null}
              </div>
              <div className="text-right">
                <div className={`text-sm font-semibold ${e.direction === 'credit' ? 'text-emerald-700' : 'text-red-700'}`}>
                  {e.direction === 'credit' ? '+' : '-'}{formatMoney(e.amount, e.currency)}
                </div>
                <StatusBadge status={e.status} />
              </div>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
