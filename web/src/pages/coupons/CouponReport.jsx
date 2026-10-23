import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { formatPaise } from '../../lib/licensing.js'
import { COUPON_ADMIN } from '../../lib/coupons.js'
import { RedemptionsTable } from './CouponDetail.jsx'

function Totals({ rows, keyLabel, renderKey }) {
  return (
    <table className="engage-table">
      <thead><tr><th>{keyLabel}</th><th className="text-right">Used</th><th className="text-right">Held</th><th className="text-right">Released</th><th className="text-right">Reversed</th>
        <th className="text-right">List</th><th className="text-right">Discount</th><th className="text-right">Net</th><th className="text-right">Net before GST</th></tr></thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i}>
            <td className="text-xs">{renderKey(r)}</td>
            <td className="text-right text-xs">{r.committed}</td><td className="text-right text-xs">{r.reserved}</td>
            <td className="text-right text-xs">{r.released}</td><td className="text-right text-xs">{r.reversed}</td>
            <td className="text-right text-xs">{formatPaise(r.list_paise)}</td><td className="text-right text-xs">{formatPaise(r.discount_paise)}</td>
            <td className="text-right text-xs">{formatPaise(r.net_paise)}</td><td className="text-right text-xs">{formatPaise(r.taxable_paise)}</td>
          </tr>
        ))}
        {rows.length === 0 ? <tr><td colSpan={9} className="text-xs text-neutral-500">Nothing in this period.</td></tr> : null}
      </tbody>
    </table>
  )
}

/** Redemptions report: per coupon, per seller and in total (list, discount, net) for redemptions created in a period. */
export default function CouponReport() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(COUPON_ADMIN)
  const [f, setF] = useState({ from: '', to: '', state: '' })
  const [error, setError] = useState(null)
  const params = Object.fromEntries(Object.entries({ from: f.from, to: f.to }).filter(([, v]) => v))
  const rep = useQuery({ queryKey: ['coupon-report', params], queryFn: () => api.get('/v1/coupon-report', { params }).then((r) => r.data?.data) })
  const reds = useQuery({ queryKey: ['coupon-redemptions', f.state], queryFn: () => api.get('/v1/coupon-redemptions', { params: f.state ? { state: f.state } : {} }).then((r) => r.data?.data?.items || []) })
  const act = useMutation({
    mutationFn: ({ rid, action, reason }) => api.post(`/v1/coupon-redemptions/${rid}/${action}`, { reason }),
    onSuccess: () => { setError(null); qc.invalidateQueries({ queryKey: ['coupon-redemptions'] }); qc.invalidateQueries({ queryKey: ['coupon-report'] }) },
    onError: (e) => setError(describeError(e)),
  })
  const t = rep.data?.totals

  return (
    <>
      <PageHeader title="Coupon report" subtitle="What coupons gave away and what was invoiced. Net = billed after discount; seller commission is on the net before GST." />
      <ErrorNote error={error} />
      <div className="flex flex-wrap gap-2 mb-3 items-end">
        <div><div className="engage-label">From</div><input className="engage-input" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></div>
        <div><div className="engage-label">To (last day)</div><input className="engage-input" type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></div>
      </div>
      {t ? (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
          {[['Used', t.committed], ['Held by quotes', t.reserved], ['List value', formatPaise(t.list_paise)], ['Discount given', formatPaise(t.discount_paise)], ['Net invoiced', formatPaise(t.net_paise)]].map(([k, v]) => (
            <div key={k} className="engage-card"><div className="text-xs text-neutral-400">{k}</div><div className="text-lg font-semibold">{v}</div></div>
          ))}
        </div>
      ) : null}
      <div className="engage-card mb-4"><div className="font-semibold mb-2">Per coupon</div>
        <Totals rows={rep.data?.by_coupon || []} keyLabel="Coupon" renderKey={(r) => <Link className="text-aicountly-700 hover:underline" to={`/coupons/${r.coupon_id}`}>{r.coupon_ref} · {r.label}</Link>} /></div>
      <div className="engage-card mb-4"><div className="font-semibold mb-2">Per seller</div>
        <Totals rows={rep.data?.by_seller || []} keyLabel="Seller" renderKey={(r) => (r.seller_id ? <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller_id}`}>{r.seller_code}</Link> : 'No seller')} /></div>
      <div className="engage-card">
        <div className="flex items-center justify-between mb-2"><div className="font-semibold">Redemptions</div>
          <select className="engage-input w-40 text-xs" value={f.state} onChange={(e) => setF({ ...f, state: e.target.value })}>
            <option value="">All states</option><option value="reserved">Reserved</option><option value="committed">Committed</option><option value="released">Released</option><option value="reversed">Reversed</option>
          </select></div>
        <RedemptionsTable items={reds.data || []} canEdit={canEdit} onAction={(rid, action, reason) => act.mutate({ rid, action, reason })} />
      </div>
    </>
  )
}
