import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate } from '../../lib/format.js'

/** Lead outcome events queued for Reach (engage_reach_outcome_outbox). */
export default function ReachOutcomes() {
  const q = useQuery({ queryKey: ['reach-outcomes'], queryFn: () => api.get('/v1/status/reach-outcomes').then((r) => r.data?.data) })
  const d = q.data || {}
  return (
    <>
      <PageHeader title="Reach outcomes" subtitle="Stage changes and confirmed payments of Reach leads, sent to reach.aicountly.org every 5 minutes." />
      <div className={`mb-4 rounded-md text-sm px-3 py-2 border ${d.configured ? 'bg-green-50 border-green-200 text-green-900' : 'bg-amber-50 border-amber-200 text-amber-900'}`}>
        {d.status || '…'} · pending {d.counts?.pending ?? 0} · delivered {d.counts?.delivered ?? 0} · rejected {d.counts?.rejected ?? 0} · dead {d.counts?.dead ?? 0}
      </div>
      <DataTable
        loading={q.isLoading}
        rows={d.recent || []}
        emptyMessage="No outcome events yet."
        columns={[
          { key: 'event_id', header: 'Event', render: (r) => <span className="font-mono text-xs">{r.event_id}</span> },
          { key: 'stage', header: 'Stage' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'attempts', header: 'Attempts' },
          { key: 'last_error', header: 'Last error', render: (r) => <span className="text-xs">{r.last_error || '—'}</span> },
          { key: 'created_at', header: 'Queued', render: (r) => formatDate(r.created_at, { withTime: true }) },
        ]}
      />
    </>
  )
}
