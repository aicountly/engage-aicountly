import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import Drawer from '../components/Drawer.jsx'
import { formatDate, formatMoney } from '../lib/format.js'
import { useAuth } from '../lib/auth.jsx'
import { DECISION_ROLES } from '../lib/coupons.js'

// The table has discount_percent / discount_amount (migration 2026-07-03-000034); the screen used to read
// discount_type / amount, which do not exist.
const discountText = (r) => (Number(r.discount_percent) > 0 ? `${Number(r.discount_percent)}%` : formatMoney(r.discount_amount, r.currency))

export default function DiscountRequests() {
  const qc = useQueryClient()
  const [f, setF] = useState({ status: 'pending' })
  const [current, setCurrent] = useState(null)
  const [reason, setReason] = useState('')
  const [msg, setMsg] = useState(null)
  // Approve / reject: super_admin | sales_manager (the API enforces the same; Routes.php).
  const { hasRole } = useAuth()
  const canDecide = hasRole(DECISION_ROLES)

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
          { key: 'discount', header: 'Discount', align: 'right', render: (r) => discountText(r) },
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
            <div><span className="text-xs text-neutral-500">Discount:</span> {discountText(current)}</div>
            <div><span className="text-xs text-neutral-500">Justification:</span> {current.justification || '—'}</div>
            <StatusBadge status={current.status} />
          </div>
          {current.status === 'pending' && !canDecide ? (
            <div className="mt-4 text-xs text-neutral-500">Only an administrator or a sales manager can approve or reject a discount.</div>
          ) : null}
          {current.status === 'pending' && canDecide ? (
            <div className="mt-4 space-y-2">
              <label className="engage-label">Decision note</label>
              <textarea className="engage-input" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
              <div className="flex gap-2 justify-end pt-2">
                <button className="engage-btn-danger" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'reject', payload: { notes: reason } })}>Reject</button>
                <button className="engage-btn-primary" disabled={mutate.isPending} onClick={() => mutate.mutate({ id: current.id, action: 'approve', payload: { notes: reason } })}>Approve</button>
              </div>
            </div>
          ) : null}
        </Drawer>
      ) : null}
    </>
  )
}
