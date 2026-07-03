import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

export default function WorkerStatus() {
  const { data, isLoading } = useQuery({
    queryKey: ['worker-status'],
    queryFn: () => api.get('/v1/status/worker').then((r) => r.data?.data || r.data),
    refetchInterval: 30_000,
  })
  const summary = data?.summary || {}
  const rows = data?.recent || data?.rows || []

  return (
    <>
      <PageHeader
        title="Playwright worker status"
        subtitle="worker.apis.aicountly.com is used only for Playwright UI/screenshot/review jobs."
      />

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <Metric label="Success (24h)" value={summary.success_24h ?? 0} />
        <Metric label="Failed (24h)" value={summary.failed_24h ?? 0} tone={summary.failed_24h ? 'danger' : 'default'} />
        <Metric label="Last ping" value={summary.last_ping_at ? formatDate(summary.last_ping_at, { withTime: true }) : '—'} />
        <Metric label="Base URL" value={summary.base_url ?? '—'} />
      </div>

      <DataTable
        loading={isLoading}
        columns={[
          { key: 'created_at', header: 'When', nowrap: true, render: (r) => formatDate(r.created_at, { withTime: true }) },
          { key: 'endpoint', header: 'Endpoint', render: (r) => titleCase(r.endpoint) },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'latency_ms', header: 'Latency ms' },
          { key: 'error_message', header: 'Error' },
        ]}
        rows={rows}
        emptyMessage="No worker events yet."
      />
    </>
  )
}

function Metric({ label, value, tone = 'default' }) {
  const cls = tone === 'danger' ? 'bg-red-50 border-red-200 text-red-900' : 'bg-white border-neutral-200 text-neutral-900'
  return (
    <div className={`rounded-xl border p-4 shadow-sm ${cls}`}>
      <div className="text-xs uppercase tracking-wide opacity-70">{label}</div>
      <div className="text-lg font-semibold mt-1 break-all">{value}</div>
    </div>
  )
}
