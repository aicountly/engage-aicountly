import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { COMMISSION_ADMIN, inr } from '../../lib/sellerRoles.js'

export default function CommissionEntries() {
  const [params] = useSearchParams()
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canReverse = hasRole(COMMISSION_ADMIN)
  const [status, setStatus] = useState('')
  const [error, setError] = useState(null)
  const sellerId = params.get('seller_id') || ''
  const qs = new URLSearchParams(Object.fromEntries(Object.entries({ status, seller_id: sellerId }).filter(([, v]) => v))).toString()
  const q = useQuery({ queryKey: ['commission-entries', qs], queryFn: () => api.get(`/v1/commission-entries?limit=200&${qs}`).then((r) => r.data?.data) })
  const reverse = useMutation({
    mutationFn: ({ id, reason }) => api.post(`/v1/commission-entries/${id}/reverse`, { reason }).then((r) => r.data?.data),
    onSuccess: () => { setError(null); qc.invalidateQueries({ queryKey: ['commission-entries'] }) },
    onError: (e) => setError(apiError(e)),
  })

  return (
    <>
      <PageHeader title="Commission entries" subtitle="Accrued on a converted lead's confirmed payment → approved after the plan's hold → (payouts: not enabled yet)." />
      {error ? <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      <div className="mb-3 flex gap-2">
        {['', 'accrued', 'approved', 'in_payout', 'paid', 'reversed'].map((s) => (
          <button key={s || 'all'} className={s === status ? 'engage-btn-primary' : 'engage-btn-secondary'} onClick={() => setStatus(s)}>{s ? titleCase(s) : 'All'}</button>
        ))}
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyMessage="No commission entries. Nothing accrues until a commission plan is published."
        columns={[
          { key: 'seller_code', header: 'Seller', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller_id}`}>{r.seller_code}</Link> },
          { key: 'lead_code', header: 'Lead', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/leads/${r.lead_id}`}>{r.lead_code}</Link> },
          { key: 'payment_ref', header: 'Payment' },
          { key: 'plan_code', header: 'Plan', render: (r) => `${r.plan_code} v${r.plan_version}` },
          { key: 'basis_amount', header: 'Basis', align: 'right', render: (r) => inr(r.basis_amount) },
          { key: 'amount', header: 'Commission', align: 'right', render: (r) => <span title={r.explain}>{inr(r.amount)}</span> },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'hold_until', header: 'Hold until', render: (r) => formatDate(r.hold_until) },
          { key: 'actions', header: '', render: (r) => (canReverse && ['accrued', 'approved'].includes(r.status) ? (
            <button className="engage-btn-danger text-xs" onClick={() => { const reason = window.prompt('Reverse this entry — reason:'); if (reason) reverse.mutate({ id: r.id, reason }) }}>Reverse</button>
          ) : r.reversal_reason ? <span className="text-xs text-neutral-500">{r.reversal_reason}</span> : null) },
        ]}
      />
    </>
  )
}
