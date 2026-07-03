import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import EmptyState from '../components/EmptyState.jsx'
import { formatMoney } from '../lib/format.js'

function Stat({ label, value, tone = 'default' }) {
  const cls =
    tone === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-900' :
    tone === 'warn'    ? 'bg-amber-50 border-amber-200 text-amber-900' :
    tone === 'danger'  ? 'bg-red-50 border-red-200 text-red-900' :
    'bg-white border-neutral-200 text-neutral-900'
  return (
    <div className={`rounded-xl border p-4 shadow-sm ${cls}`}>
      <div className="text-xs uppercase tracking-wide opacity-70">{label}</div>
      <div className="text-2xl font-semibold mt-1">{value ?? '—'}</div>
    </div>
  )
}

export default function Dashboard() {
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get('/v1/dashboard/summary').then((r) => r.data?.data || r.data),
  })

  const s = data || {}

  return (
    <>
      <PageHeader
        title="Engage dashboard"
        subtitle="Sales, licensing, credit, bot activity and approvals across AICOUNTLY."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <Stat label="Open leads" value={s.totals?.leads_open} />
        <Stat label="All leads" value={s.totals?.leads_total} />
        <Stat label="Converted (7d)" value={s.totals?.leads_won_7d} tone="success" />
        <Stat label="Lost (7d)" value={s.totals?.leads_lost_7d} tone="danger" />
        <Stat label="Pipeline value" value={formatMoney(s.pipeline_value)} />
        <Stat label="Follow-ups (7d)" value={s.follow_ups?.upcoming_7d} />
        <Stat label="Follow-ups overdue" value={s.follow_ups?.overdue} tone={s.follow_ups?.overdue ? 'warn' : 'default'} />
        <Stat label="Renewals due (30d)" value={s.renewals_30d} />
        <Stat label="Bot reports (24h)" value={s.bot_reports?.last_24h} />
        <Stat label="Bot reports (7d)" value={s.bot_reports?.last_7d} />
        <Stat label="Pending approvals" value={s.approvals_pending} tone={s.approvals_pending ? 'warn' : 'default'} />
        <Stat label="Credit approvals" value={s.credit_pending_approval} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="engage-card">
          <div className="flex items-center justify-between mb-3">
            <div className="text-sm font-semibold text-neutral-900">Pipeline stage counts</div>
            <Link to="/pipeline" className="text-xs text-aicountly-700 font-medium hover:underline">Open pipeline →</Link>
          </div>
          {isLoading ? (
            <div className="text-sm text-neutral-500">Loading…</div>
          ) : (s.stage_counts || []).length === 0 ? (
            <EmptyState title="No pipeline data" message="Create leads to populate the sales pipeline." />
          ) : (
            <ul className="divide-y divide-neutral-100">
              {(s.stage_counts || []).map((c) => (
                <li key={c.code} className="py-2 flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span className="inline-block h-2.5 w-2.5 rounded" style={{ background: c.colour || '#16a34a' }} />
                    <span className="text-sm text-neutral-800">{c.name}</span>
                  </div>
                  <span className="text-sm font-semibold text-neutral-900">{c.count}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="engage-card">
          <div className="text-sm font-semibold text-neutral-900 mb-3">Quick links</div>
          <div className="grid grid-cols-2 gap-2">
            <Link to="/leads" className="engage-btn-secondary justify-start">Leads</Link>
            <Link to="/pipeline" className="engage-btn-secondary justify-start">Pipeline</Link>
            <Link to="/bot/reports" className="engage-btn-secondary justify-start">Bot reports</Link>
            <Link to="/bot/settings" className="engage-btn-secondary justify-start">Bot settings</Link>
            <Link to="/approvals" className="engage-btn-secondary justify-start">Approvals</Link>
            <Link to="/renewals" className="engage-btn-secondary justify-start">Renewals</Link>
            <Link to="/proposals" className="engage-btn-secondary justify-start">Proposals</Link>
            <Link to="/audit-logs" className="engage-btn-secondary justify-start">Audit logs</Link>
          </div>
        </div>
      </div>
    </>
  )
}
