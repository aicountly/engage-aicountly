import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import { formatDate, titleCase } from '../lib/format.js'
import { ApprovalBadge } from '../components/Badges.jsx'

export default function LocalBotReports() {
  const { data, isLoading } = useQuery({
    queryKey: ['bot-reports-local'],
    queryFn: () => api.get('/v1/bot/reports-local').then((r) => r.data?.data || r.data),
  })
  const summary = data?.summary || {}
  const recent = data?.recent || data?.rows || []

  return (
    <>
      <PageHeader
        title="Local bot reports"
        subtitle="Roll-up of everything the bot did in the last day / week / month. Detailed reports remain in Bot reports."
        actions={<Link to="/bot/reports" className="engage-btn-secondary">All reports →</Link>}
      />

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <Metric label="Last 24h" value={summary.last_24h ?? 0} />
        <Metric label="Last 7d" value={summary.last_7d ?? 0} />
        <Metric label="Last 30d" value={summary.last_30d ?? 0} />
        <Metric label="Total" value={summary.total ?? 0} />
      </div>

      <div className="engage-card">
        <div className="text-sm font-semibold mb-2">Recent activity</div>
        {isLoading ? <div>Loading…</div> : recent.length === 0 ? (
          <div className="text-sm text-neutral-500">No reports yet.</div>
        ) : (
          <ol className="divide-y divide-neutral-100">
            {recent.map((r) => (
              <li key={r.id} className="py-2 flex items-center justify-between gap-2">
                <div>
                  <div className="text-sm font-medium text-neutral-900"><Link className="text-aicountly-700 hover:underline" to={`/bot/reports/${r.id}`}>{titleCase(r.action_code)}</Link></div>
                  <div className="text-xs text-neutral-500">{formatDate(r.created_at, { withTime: true })} · {r.subject_kind}{r.subject_id ? ` #${r.subject_id}` : ''}</div>
                </div>
                <ApprovalBadge status={r.approval_status || 'not_required'} />
              </li>
            ))}
          </ol>
        )}
      </div>
    </>
  )
}

function Metric({ label, value }) {
  return (
    <div className="engage-card">
      <div className="text-xs uppercase tracking-wide text-neutral-500">{label}</div>
      <div className="text-2xl font-semibold text-neutral-900 mt-1">{value}</div>
    </div>
  )
}
