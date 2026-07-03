import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import Drawer from '../components/Drawer.jsx'
import { formatDate, formatMoney, titleCase } from '../lib/format.js'

export default function DiscountRequests() {
  const qc = useQueryClient()
  const [f, setF] = useState({ status: 'pending' })
  const [current, setCurrent] = useState(null)
  const [reason, setReason] = useState('')
  const [msg, setMsg] = useState(null)

  const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()

  const listQ = useQuery({
    queryKey: ['discount-requests', q],
    queryFn: () => api.get(`/v1/discount-requests?${q}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const mutate = useMutation({
    mutationFn: ({ id, action, payload }) => api.post(`/v1/discount-requests/${id}/${action}`, payload || {}).then((r) => r.data),
    onSuccess: (res, vars) => { setMsg({ ok: true, text: `Discount ${vars.action}d.` }); setCurrent(null); qc.invalidateQueries({ queryKey: ['discount-requests'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  return (
    <>
      <PageHeader
        title="Pricing / discount requests"
        subtitle="Discount asks recorded per proposal, gated by the approval workflow."
      />
      {msg ? <div className={`mb-3 rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>{msg.text}</div> : null}

      <FilterBar
        filters={[
          { key: 'status', label: 'Status', type: 'select', options: [
            { value: 'pending', label: 'pending' }, { value: 'approved', label: 'approved' },
            { value: 'rejected', label: 'rejected' }, { value: 'applied', label: 'applied' },
          ] },
        ]}
        value={f}
        onChange={setF}
      />

      <DataTable
        loading={listQ.isLoading}
        columns={[
          { key: 'id', header: '#', nowrap: true },
          { key: 'proposal_id', header: 'Proposal' },
          { key: 'discount_type', header: 'Type', render: (r) => titleCase(r.discount_type) },
          { key: 'amount', header: 'Amount', align: 'right', render: (r) => r.discount_type === 'percent' ? `${r.amount}%` : formatMoney(r.amount, r.currency) },
          { key: 'requested_by', header: 'Requested by' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'created_at', header: 'When', render: (r) => formatDate(r.created_at, { withTime: true }) },
        ]}
        rows={listQ.data || []}
        onRowClick={(r) => { setCurrent(r); setReason('') }}
      />

      {current ? (
        <Drawer open title={`Discount #${current.id}`} onClose={() => setCurrent(null)}>
          <div className="text-sm space-y-1">
            <div><span className="text-xs text-neutral-500">Proposal:</span> #{current.proposal_id}</div>
            <div><span className="text-xs text-neutral-500">Amount:</span> {current.discount_type === 'percent' ? `${current.amount}%` : formatMoney(current.amount, current.currency)}</div>
            <div><span className="text-xs text-neutral-500">Reason:</span> {current.reason || '—'}</div>
            <StatusBadge status={current.status} />
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
          ) : null}
        </Drawer>
      ) : null}
    </>
  )
}
