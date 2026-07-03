import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import Drawer from '../components/Drawer.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { ApprovalBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

export default function Approvals() {
  const qc = useQueryClient()
  const [f, setF] = useState({ status: 'pending' })
  const [current, setCurrent] = useState(null)
  const [reason, setReason] = useState('')
  const [msg, setMsg] = useState(null)

  const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()

  const listQ = useQuery({
    queryKey: ['approvals', q],
    queryFn: () => api.get(`/v1/approvals?${q}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const mutate = useMutation({
    mutationFn: ({ id, action, payload }) => api.post(`/v1/approvals/${id}/${action}`, payload || {}).then((r) => r.data),
    onSuccess: (res, vars) => {
      setMsg({ ok: true, text: `Approval ${vars.action}d.` })
      setCurrent(null); setReason('')
      qc.invalidateQueries({ queryKey: ['approvals'] })
    },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  return (
    <>
      <PageHeader
        title="Approvals"
        subtitle="High-risk sales bot / user actions gated by superadmin approval, mirrored to Console."
      />

      {msg ? <div className={`mb-3 rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>{msg.text}</div> : null}

      <FilterBar
        filters={[
          { key: 'status', label: 'Status', type: 'select', options: [
            { value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' },
            { value: 'rejected', label: 'Rejected' }, { value: 'executed', label: 'Executed' },
            { value: 'cancelled', label: 'Cancelled' },
          ] },
          { key: 'action_code', label: 'Action code' },
          { key: 'subject_kind', label: 'Subject kind' },
        ]}
        value={f}
        onChange={setF}
      />

      <DataTable
        loading={listQ.isLoading}
        columns={[
          { key: 'id', header: '#', nowrap: true },
          { key: 'action_code', header: 'Action', render: (r) => titleCase(r.action_code) },
          { key: 'subject_kind', header: 'Subject', render: (r) => r.subject_kind ? `${r.subject_kind}${r.subject_id ? ` #${r.subject_id}` : ''}` : '—' },
          { key: 'requested_by', header: 'Requested by' },
          { key: 'status', header: 'Status', render: (r) => <ApprovalBadge status={r.status} /> },
          { key: 'created_at', header: 'Requested', render: (r) => formatDate(r.created_at, { withTime: true }) },
        ]}
        rows={listQ.data || []}
        onRowClick={(r) => { setCurrent(r); setReason('') }}
        emptyMessage="No approval requests."
      />

      {current ? (
        <Drawer open title={`Approval #${current.id}`} onClose={() => setCurrent(null)}>
          <div className="text-sm space-y-2">
            <div><span className="text-neutral-500 text-xs">Action:</span> <span className="font-medium">{titleCase(current.action_code)}</span></div>
            <div><span className="text-neutral-500 text-xs">Subject:</span> {current.subject_kind}{current.subject_id ? ` #${current.subject_id}` : ''}</div>
            <div><span className="text-neutral-500 text-xs">Requested by:</span> {current.requested_by || '—'}</div>
            <div><span className="text-neutral-500 text-xs">Reason:</span> {current.reason || '—'}</div>
            <div><ApprovalBadge status={current.status} /></div>
            {current.payload ? (
              <div>
                <div className="text-neutral-500 text-xs mt-2">Payload</div>
                <pre className="text-xs bg-neutral-50 border border-neutral-200 rounded p-2 whitespace-pre-wrap">{JSON.stringify(typeof current.payload === 'string' ? JSON.parse(current.payload) : current.payload, null, 2)}</pre>
              </div>
            ) : null}
          </div>
          {current.status === 'pending' ? (
            <div className="mt-4 space-y-2">
              <label className="engage-label">Decision note</label>
              <textarea className="engage-input" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
              <div className="flex gap-2 justify-end pt-2">
                <button className="engage-btn-danger" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'reject', payload: { reason } })}>Reject</button>
                <button className="engage-btn-primary" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'approve', payload: { reason } })}>Approve</button>
              </div>
            </div>
          ) : current.status === 'approved' ? (
            <div className="mt-4 flex justify-end">
              <button className="engage-btn-primary" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'execute' })}>Execute now</button>
            </div>
          ) : null}
        </Drawer>
      ) : null}
    </>
  )
}
