import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { formatDate } from '../lib/format.js'
import Drawer from '../components/Drawer.jsx'

export default function AuditLogs() {
  const [f, setF] = useState({})
  const [current, setCurrent] = useState(null)
  const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()

  const { data, isLoading } = useQuery({
    queryKey: ['audit-logs', q],
    queryFn: () => api.get(`/v1/audit-logs?per_page=100&${q}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
    keepPreviousData: true,
  })

  return (
    <>
      <PageHeader
        title="Audit logs"
        subtitle="Every material change, bot action, approval decision and Console fan-out."
      />
      <FilterBar
        filters={[
          { key: 'action', label: 'Action', placeholder: 'e.g. lead.assign' },
          { key: 'subject_kind', label: 'Subject kind' },
          { key: 'subject_id', label: 'Subject ID', type: 'number' },
          { key: 'user_id', label: 'User ID', type: 'number' },
        ]}
        value={f}
        onChange={setF}
      />
      <DataTable
        loading={isLoading}
        columns={[
          { key: 'created_at', header: 'Time', nowrap: true, render: (r) => formatDate(r.created_at, { withTime: true }) },
          { key: 'action', header: 'Action' },
          { key: 'subject_kind', header: 'Subject', render: (r) => r.subject_kind ? `${r.subject_kind}${r.subject_id ? ` #${r.subject_id}` : ''}` : '—' },
          { key: 'actor', header: 'Actor', render: (r) => r.user_email || r.user_id || r.actor || '—' },
          { key: 'summary', header: 'Summary' },
        ]}
        rows={data || []}
        onRowClick={setCurrent}
      />

      {current ? (
        <Drawer open title={`Audit log #${current.id}`} onClose={() => setCurrent(null)}>
          <div className="text-sm space-y-2">
            <div><span className="text-xs text-neutral-500">Time:</span> {formatDate(current.created_at, { withTime: true })}</div>
            <div><span className="text-xs text-neutral-500">Action:</span> <span className="font-medium">{current.action}</span></div>
            <div><span className="text-xs text-neutral-500">Subject:</span> {current.subject_kind}{current.subject_id ? ` #${current.subject_id}` : ''}</div>
            <div><span className="text-xs text-neutral-500">Actor:</span> {current.user_email || current.user_id || '—'}</div>
            {current.summary ? <div><span className="text-xs text-neutral-500">Summary:</span> {current.summary}</div> : null}
            <div>
              <div className="text-xs text-neutral-500 mt-2">Metadata</div>
              <pre className="text-xs bg-neutral-50 border border-neutral-200 rounded p-2 whitespace-pre-wrap">{JSON.stringify(typeof current.metadata === 'string' ? JSON.parse(current.metadata || '{}') : current.metadata || {}, null, 2)}</pre>
            </div>
          </div>
        </Drawer>
      ) : null}
    </>
  )
}
