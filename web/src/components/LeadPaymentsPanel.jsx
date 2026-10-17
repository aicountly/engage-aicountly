import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api, { apiError } from '../lib/api.js'
import { StatusBadge } from './Badges.jsx'
import { formatDate } from '../lib/format.js'
import { useAuth } from '../lib/auth.jsx'
import { ATTRIBUTION_ADMIN, COMMISSION_ADMIN, inr } from '../lib/sellerRoles.js'

/** Lead detail: seller attribution, confirmed payments ("payment confirmed") and their commission entries. */
export default function LeadPaymentsPanel({ lead }) {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canConfirm = hasRole(COMMISSION_ADMIN)
  const canAttribute = hasRole(ATTRIBUTION_ADMIN)
  const [form, setForm] = useState({ amount: '', payment_ref: '', paid_at: '', notes: '' })
  const [error, setError] = useState(null)
  const [note, setNote] = useState(null)
  const q = useQuery({ queryKey: ['lead-payments', lead.id], queryFn: () => api.get(`/v1/leads/${lead.id}/payments`).then((r) => r.data?.data) })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['lead-payments', lead.id] }); qc.invalidateQueries({ queryKey: ['lead', String(lead.id)] }) }
  const confirm = useMutation({
    mutationFn: (body) => api.post(`/v1/leads/${lead.id}/payments`, body).then((r) => r.data?.data),
    onSuccess: (d) => { setError(null); setForm({ amount: '', payment_ref: '', paid_at: '', notes: '' }); setNote((d?.accrual?.notes || []).join(' ') || (d?.duplicate ? 'This payment was already recorded.' : null)); refresh() },
    onError: (e) => setError(apiError(e)),
  })
  const refund = useMutation({
    mutationFn: ({ id, reason }) => api.post(`/v1/lead-payments/${id}/refund`, { reason }).then((r) => r.data?.data),
    onSuccess: () => { setError(null); refresh() },
    onError: (e) => setError(apiError(e)),
  })
  const attribute = useMutation({
    mutationFn: (body) => api.post(`/v1/leads/${lead.id}/seller`, body).then((r) => r.data?.data),
    onSuccess: () => { setError(null); refresh() },
    onError: (e) => setError(apiError(e)),
  })

  return (
    <div className="engage-card space-y-3">
      <div className="font-semibold">Seller & payments</div>
      {error ? <div className="rounded-md bg-red-50 border border-red-200 text-red-800 text-xs px-2 py-1">{error}</div> : null}
      {note ? <div className="rounded-md bg-sky-50 border border-sky-200 text-sky-900 text-xs px-2 py-1">{note}</div> : null}
      <div className="text-sm">
        {lead.seller_id ? (
          <>Seller: <Link className="text-aicountly-700 hover:underline" to={`/sellers/${lead.seller_id}`}>#{lead.seller_id}</Link> ({lead.seller_attribution}) · protected until {formatDate(lead.seller_protection_expires_at)}</>
        ) : 'No seller attributed.'}
        {canAttribute ? (
          <button className="engage-btn-secondary text-xs ml-2" onClick={() => {
            const code = window.prompt('Seller code to attribute (empty = remove attribution):', '')
            if (code === null) return
            const reason = window.prompt('Reason (audited):')
            if (reason) attribute.mutate({ seller_code: code.trim() || null, reason })
          }}>Change</button>
        ) : null}
      </div>
      <table className="engage-table">
        <thead><tr><th>#</th><th>Ref</th><th>Amount</th><th>Paid</th><th>Status</th><th>Commission</th><th /></tr></thead>
        <tbody>
          {(q.data?.items || []).map((p) => {
            const e = (q.data?.entries || []).find((x) => x.payment_id === p.id)
            return (
              <tr key={p.id}>
                <td>{p.sequence_no}</td>
                <td className="font-mono text-xs">{p.payment_ref}</td>
                <td>{inr(p.amount)}</td>
                <td>{formatDate(p.paid_at)}</td>
                <td><StatusBadge status={p.status} /></td>
                <td className="text-xs">{e ? <>{inr(e.amount)} <StatusBadge status={e.status} /></> : (p.commission_note || 'Evaluated when the lead converts')}</td>
                <td>{canConfirm && p.status === 'confirmed' ? <button className="engage-btn-danger text-xs" onClick={() => { const reason = window.prompt('Mark refunded — reason:'); if (reason) refund.mutate({ id: p.id, reason }) }}>Refund</button> : null}</td>
              </tr>
            )
          })}
          {(q.data?.items || []).length === 0 ? <tr><td colSpan={7} className="text-xs text-neutral-500">No confirmed payments.</td></tr> : null}
        </tbody>
      </table>
      {canConfirm ? (
        <div className="grid grid-cols-2 gap-2">
          <input className="engage-input" placeholder="Amount (INR) e.g. 11800.00" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <input className="engage-input" placeholder="Invoice / receipt / gateway id" value={form.payment_ref} onChange={(e) => setForm({ ...form, payment_ref: e.target.value })} />
          <input className="engage-input" type="date" value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} />
          <input className="engage-input" placeholder="Notes" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <button className="engage-btn-primary col-span-2" disabled={confirm.isPending || !form.amount || !form.payment_ref}
            onClick={() => confirm.mutate(Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')))}>Payment confirmed</button>
        </div>
      ) : null}
    </div>
  )
}
