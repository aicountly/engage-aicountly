import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

export default function ConsoleSync() {
  const { data, isLoading } = useQuery({
    queryKey: ['console-sync'],
    queryFn: () => api.get('/v1/status/console-sync').then((r) => r.data?.data || r.data),
    refetchInterval: 30_000,
  })
  const summary = data?.summary || {}
  const rows = data?.recent || data?.rows || []

  return (
    <>
      <PageHeader
        title="Console sync status"
        subtitle="Every outbound event to console.aicountly.org and its response."
      />

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <Metric label="Sent (24h)" value={summary.sent_24h ?? 0} />
        <Metric label="Failed (24h)" value={summary.failed_24h ?? 0} tone={summary.failed_24h ? 'danger' : 'default'} />
        <Metric label="Last success" value={summary.last_success_at ? formatDate(summary.last_success_at, { withTime: true }) : '—'} />
        <Metric label="Last failure" value={summary.last_failure_at ? formatDate(summary.last_failure_at, { withTime: true }) : '—'} />
      </div>

      <DataTable
        loading={isLoading}
        columns={[
          { key: 'created_at', header: 'When', nowrap: true, render: (r) => formatDate(r.created_at, { withTime: true }) },
          { key: 'event_type', header: 'Event', render: (r) => titleCase(r.event_type) },
          { key: 'direction', header: 'Direction', render: (r) => titleCase(r.direction || 'out') },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'response_code', header: 'HTTP' },
          { key: 'error_message', header: 'Error' },
        ]}
        rows={rows}
        emptyMessage="No Console sync events yet."
      />
    </>
  )
}

function Metric({ label, value, tone = 'default' }) {
  const cls = tone === 'danger' ? 'bg-red-50 border-red-200 text-red-900' : 'bg-white border-neutral-200 text-neutral-900'
  return (
    <div className={`rounded-xl border p-4 shadow-sm ${cls}`}>
      <div className="text-xs uppercase tracking-wide opacity-70">{label}</div>
      <div className="text-lg font-semibold mt-1">{value}</div>
    </div>
  )
}
