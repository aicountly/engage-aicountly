import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import Drawer from '../components/Drawer.jsx'
import { formatDate, titleCase, truncate } from '../lib/format.js'

export default function Communications() {
  const qc = useQueryClient()
  const [f, setF] = useState({ status: 'pending_approval' })
  const [current, setCurrent] = useState(null)
  const [reason, setReason] = useState('')
  const [msg, setMsg] = useState(null)

  const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()

  const listQ = useQuery({
    queryKey: ['communication-drafts', q],
    queryFn: () => api.get(`/v1/communication-drafts?${q}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const mutate = useMutation({
    mutationFn: ({ id, action, payload }) => api.post(`/v1/communication-drafts/${id}/${action}`, payload || {}).then((r) => r.data),
    onSuccess: (res, vars) => { setMsg({ ok: true, text: `Draft ${vars.action}d.` }); setCurrent(null); qc.invalidateQueries({ queryKey: ['communication-drafts'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  return (
    <>
      <PageHeader
        title="Communication drafts"
        subtitle="Bot / user drafted messages awaiting approval before send."
      />
      {msg ? <div className={`mb-3 rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>{msg.text}</div> : null}
      <FilterBar
        filters={[
          { key: 'channel', label: 'Channel', type: 'select', options: [
            { value: 'email', label: 'email' }, { value: 'whatsapp', label: 'whatsapp' }, { value: 'sms', label: 'sms' },
          ] },
          { key: 'status', label: 'Status', type: 'select', options: [
            { value: 'draft', label: 'draft' }, { value: 'pending_approval', label: 'pending_approval' },
            { value: 'approved', label: 'approved' }, { value: 'sent', label: 'sent' },
            { value: 'rejected', label: 'rejected' }, { value: 'cancelled', label: 'cancelled' },
          ] },
        ]}
        value={f}
        onChange={setF}
      />

      <DataTable
        loading={listQ.isLoading}
        columns={[
          { key: 'created_at', header: 'When', nowrap: true, render: (r) => formatDate(r.created_at, { withTime: true }) },
          { key: 'channel', header: 'Channel', render: (r) => titleCase(r.channel) },
          { key: 'lead_id', header: 'Lead' },
          { key: 'subject', header: 'Subject/preview', render: (r) => r.subject || truncate(r.body_text || r.body, 60) },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        ]}
        rows={listQ.data || []}
        onRowClick={setCurrent}
      />

      {current ? (
        <Drawer open title={`Draft #${current.id}`} onClose={() => setCurrent(null)}>
          <div className="space-y-2 text-sm">
            <div><span className="text-xs text-neutral-500">Channel:</span> {titleCase(current.channel)}</div>
            <div><span className="text-xs text-neutral-500">Lead:</span> #{current.lead_id}</div>
            {current.subject ? <div><span className="text-xs text-neutral-500">Subject:</span> {current.subject}</div> : null}
            <div className="mt-2">
              <div className="text-xs text-neutral-500">Body</div>
              <pre className="text-sm bg-neutral-50 border border-neutral-200 rounded p-2 whitespace-pre-wrap">{current.body_text || current.body || '—'}</pre>
            </div>
            <StatusBadge status={current.status} />
          </div>
          {current.status === 'pending_approval' || current.status === 'draft' ? (
            <div className="mt-4 space-y-2">
              <label className="engage-label">Decision note</label>
              <textarea className="engage-input" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
              <div className="flex gap-2 justify-end pt-2">
                <button className="engage-btn-danger" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'reject', payload: { reason } })}>Reject</button>
                <button className="engage-btn-primary" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'approve', payload: { reason } })}>Approve</button>
              </div>
            </div>
          ) : null}
        </Drawer>
      ) : null}
    </>
  )
}
