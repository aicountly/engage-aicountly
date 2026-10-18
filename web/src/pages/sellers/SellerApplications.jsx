import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import OneTimeLink from '../../components/OneTimeLink.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { SELLER_ADMIN } from '../../lib/sellerRoles.js'

const STATUSES = ['requested', 'under_review', 'approved', 'rejected']

/** Requests from seller.aicountly.com ("become a seller"). Approve = create the seller + activation link. */
export default function SellerApplications() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canDecide = hasRole(SELLER_ADMIN)
  const [status, setStatus] = useState('requested')
  const [error, setError] = useState(null)
  const [delivery, setDelivery] = useState(null)
  const [warnings, setWarnings] = useState([])

  const q = useQuery({
    queryKey: ['seller-applications', status],
    queryFn: () => api.get(`/v1/seller-applications?status=${status}&limit=200`).then((r) => r.data?.data),
  })
  const act = useMutation({
    mutationFn: ({ id, action, body }) => api.post(`/v1/seller-applications/${id}/${action}`, body || {}).then((r) => r.data?.data),
    onSuccess: (data, vars) => {
      setError(null)
      if (vars.action === 'approve') {
        setDelivery(data?.activation || null)
        setWarnings(data?.warnings || [])
      }
      qc.invalidateQueries({ queryKey: ['seller-applications'] })
      qc.invalidateQueries({ queryKey: ['sellers'] })
    },
    onError: (e) => setError(apiError(e)),
  })

  const reject = (row) => {
    const reason = window.prompt(`Reject the application from ${row.name}? Give a reason (kept on record):`)
    if (reason) act.mutate({ id: row.id, action: 'reject', body: { reason } })
  }

  return (
    <>
      <PageHeader title="Seller applications" subtitle="Requests to become an AICOUNTLY seller (seller.aicountly.com). Each one is also an Engage lead." />
      {error ? <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      {warnings.map((w) => <div key={w} className="mb-4 rounded-md bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">{w}</div>)}
      <OneTimeLink delivery={delivery} onDismiss={() => setDelivery(null)} />
      <div className="mb-3 flex gap-2">
        {STATUSES.map((s) => (
          <button key={s} className={s === status ? 'engage-btn-primary' : 'engage-btn-secondary'} onClick={() => setStatus(s)}>{titleCase(s)}</button>
        ))}
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyMessage="No applications with this status."
        columns={[
          { key: 'application_code', header: 'Code', render: (r) => <span className="font-mono text-xs">{r.application_code}</span> },
          { key: 'name', header: 'Applicant', render: (r) => <div><div className="font-medium">{r.name}</div><div className="text-xs text-neutral-500">{r.organization || '—'}</div></div> },
          { key: 'email', header: 'Contact', render: (r) => <div className="text-xs"><div>{r.email}</div><div>{r.mobile}</div></div> },
          { key: 'city', header: 'Location', render: (r) => [r.city, r.state].filter(Boolean).join(', ') || '—' },
          { key: 'entity_type', header: 'Entity', render: (r) => titleCase(r.entity_type || '—') },
          { key: 'message', header: 'How they will sell', render: (r) => <span className="text-xs">{r.message || '—'}</span> },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'created_at', header: 'Received', render: (r) => formatDate(r.created_at, { withTime: true }) },
          {
            key: 'actions', header: '', render: (r) => (
              <div className="flex gap-1 justify-end">
                {r.lead_id ? <Link className="engage-btn-secondary text-xs" to={`/leads/${r.lead_id}`}>Lead</Link> : null}
                {r.seller_id ? <Link className="engage-btn-secondary text-xs" to={`/sellers/${r.seller_id}`}>Seller</Link> : null}
                {canDecide && r.status === 'requested' ? <button className="engage-btn-secondary text-xs" onClick={() => act.mutate({ id: r.id, action: 'review' })}>Review</button> : null}
                {canDecide && ['requested', 'under_review'].includes(r.status) ? (
                  <>
                    <button className="engage-btn-primary text-xs" disabled={act.isPending} onClick={() => { if (window.confirm(`Approve ${r.name} as a seller? An activation link is issued.`)) act.mutate({ id: r.id, action: 'approve' }) }}>Approve</button>
                    <button className="engage-btn-danger text-xs" onClick={() => reject(r)}>Reject</button>
                  </>
                ) : null}
                {r.decision_reason ? <span className="text-xs text-neutral-500" title={r.decision_reason}>ⓘ</span> : null}
              </div>
            ),
          },
        ]}
      />
    </>
  )
}
