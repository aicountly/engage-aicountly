import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'
import { useState } from 'react'

export default function BotQueue() {
  const qc = useQueryClient()
  const [msg, setMsg] = useState(null)
  const listQ = useQuery({
    queryKey: ['bot-queue'],
    queryFn: () => api.get('/v1/bot/queue?per_page=100').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const actionsQ = useQuery({
    queryKey: ['bot-actions'],
    queryFn: () => api.get('/v1/bot/actions').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const retry = useMutation({
    mutationFn: (id) => api.post(`/v1/bot/queue/${id}/retry`).then((r) => r.data),
    onSuccess: () => { setMsg({ ok: true, text: 'Retry queued.' }); qc.invalidateQueries({ queryKey: ['bot-queue'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  const enqueue = useMutation({
    mutationFn: (payload) => api.post('/v1/bot/queue', payload).then((r) => r.data),
    onSuccess: () => { setMsg({ ok: true, text: 'Action queued.' }); qc.invalidateQueries({ queryKey: ['bot-queue'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  return (
    <>
      <PageHeader
        title="Bot queue"
        subtitle="Every requested/executed bot action. Retry failed items or trigger fleet-wide sweeps."
        actions={
          <>
            <button className="engage-btn-secondary" onClick={() => enqueue.mutate({ action_code: 'identify_stale' })} disabled={enqueue.isPending}>Sweep stale leads</button>
            <button className="engage-btn-secondary" onClick={() => enqueue.mutate({ action_code: 'identify_hot' })} disabled={enqueue.isPending}>Sweep hot leads</button>
            <button className="engage-btn-secondary" onClick={() => enqueue.mutate({ action_code: 'prepare_renewal' })} disabled={enqueue.isPending}>Renewal sweep</button>
          </>
        }
      />

      {msg ? (
        <div className={`mb-3 rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>{msg.text}</div>
      ) : null}

      <DataTable
        loading={listQ.isLoading}
        columns={[
          { key: 'id', header: '#', nowrap: true },
          { key: 'action_code', header: 'Action', render: (r) => titleCase(r.action_code) },
          { key: 'subject_kind', header: 'Subject', render: (r) => r.subject_kind ? `${r.subject_kind}${r.subject_id ? ` #${r.subject_id}` : ''}` : '—' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'requester_kind', header: 'Requester' },
          { key: 'created_at', header: 'Queued', render: (r) => formatDate(r.created_at, { withTime: true }) },
          { key: 'report_id', header: 'Report', render: (r) => r.report_id ? <Link className="text-aicountly-700 hover:underline" to={`/bot/reports/${r.report_id}`}>#{r.report_id}</Link> : '—' },
          { key: 'actions', header: '', align: 'right', render: (r) => (
            <button className="engage-btn-secondary text-xs" onClick={() => retry.mutate(r.id)} disabled={retry.isPending}>Retry</button>
          ) },
        ]}
        rows={listQ.data || []}
        emptyMessage="No bot actions queued."
      />

      <div className="mt-6 engage-card">
        <div className="text-sm font-semibold mb-2">Available actions</div>
        <ul className="grid grid-cols-1 md:grid-cols-2 gap-1 text-sm">
          {(actionsQ.data || []).map((a) => (
            <li key={a.code} className="text-neutral-700"><span className="font-medium">{a.code}</span> — {a.description}</li>
          ))}
        </ul>
      </div>
    </>
  )
}
