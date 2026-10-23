import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { formatDateIN, formatPaise, paiseToRupees } from '../../lib/licensing.js'
import { COUPON_ADMIN, displayCode } from '../../lib/coupons.js'

function Breakdown({ q }) {
  const incl = q.price_basis === 'inclusive'
  return (
    <div className="engage-card text-sm space-y-2">
      <div className="flex items-center justify-between">
        <div><span className="text-xs text-neutral-500">Quote</span> <span className="font-mono font-semibold text-base">{q.quote_ref}</span></div>
        <div className="text-xs text-neutral-500">valid until {formatDateIN(q.expires_at)} · {q.status}</div>
      </div>
      <table className="engage-table">
        <tbody>
          <tr><td>List price ({q.offer.label}, {incl ? 'incl.' : 'excl.'} GST {q.gst_rate}%)</td><td className="text-right">{formatPaise(q.list_paise)}</td></tr>
          <tr><td>Discount{q.coupon ? <> — <span className="font-mono">{displayCode(q.coupon.code)}</span> ({q.coupon.label})</> : ''}</td><td className="text-right">{q.discount_paise ? `− ${formatPaise(q.discount_paise)}` : '—'}</td></tr>
          <tr><td>Net ({incl ? 'incl. GST, billed' : 'taxable value'})</td><td className="text-right font-medium">{formatPaise(q.net_paise)}</td></tr>
          <tr><td>Taxable value</td><td className="text-right">{formatPaise(q.taxable_paise)}</td></tr>
          <tr><td>GST {q.gst_rate}% {incl ? '(included)' : '(added)'}</td><td className="text-right">{formatPaise(q.gst_paise)}</td></tr>
          <tr className="font-semibold"><td>Total payable</td><td className="text-right">{formatPaise(q.total_paise)}</td></tr>
        </tbody>
      </table>
      {q.redemption ? <div className="text-xs text-neutral-600">The coupon use is reserved for this quote until {formatDateIN(q.redemption.reserved_until)}.</div> : null}
      <div className="rounded-md bg-neutral-50 border border-neutral-200 p-3 text-xs space-y-1">
        <div className="font-semibold">Next steps</div>
        <div>1. Finance → Offline payments → Record payment: line amount <b>₹{paiseToRupees(q.finance_line.amount_paise)}</b>, price {q.finance_line.price_basis} of GST at {q.finance_line.gst_rate}%{q.finance_line.sac ? `, SAC ${q.finance_line.sac}` : ''}
          {q.finance_line.discount_paise ? <> — list price <b>₹{paiseToRupees(q.finance_line.list_amount_paise)}</b>, discount <b>₹{paiseToRupees(q.finance_line.discount_paise)}</b>, code <b className="font-mono">{q.finance_line.discount_ref}</b></> : null}; amount received ₹{paiseToRupees(q.total_paise)}.</div>
        <div>2. Issue (or renew) the licence with quote ref <b className="font-mono">{q.quote_ref}</b> and the invoice number — the coupon use is committed with the licence.</div>
        <div>3. Lead → Seller &amp; payments → Payment confirmed with the same quote ref: commission is computed on the net before GST ({formatPaise(q.taxable_paise)}).</div>
      </div>
    </div>
  )
}

/** Quotes: price an offer for a customer, optionally with a coupon (reserves one use for 14 days). */
export default function Quotes() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canQuote = hasRole(COUPON_ADMIN)
  const [f, setF] = useState({ holder_uuid: '', cmp_id: '', offer_code: '', coupon_code: '', kind: 'new', lead_id: '' })
  const [error, setError] = useState(null)
  const [result, setResult] = useState(null)
  const offers = useQuery({ queryKey: ['offers', 'published'], queryFn: () => api.get('/v1/offers', { params: { status: 'published' } }).then((r) => r.data?.data?.items || []) })
  const list = useQuery({ queryKey: ['quotes'], queryFn: () => api.get('/v1/quotes').then((r) => r.data?.data?.items || []) })
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const make = useMutation({
    mutationFn: () => api.post('/v1/quotes', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))).then((r) => r.data?.data),
    onSuccess: (d) => { setError(null); setResult(d); qc.invalidateQueries({ queryKey: ['quotes'] }) },
    onError: (e) => { setResult(null); setError(describeError(e)) },
  })

  return (
    <>
      <PageHeader title="Quotes" subtitle="Price an offer for a customer, with a coupon if they have one. GST is charged on the price after discount." />
      {canQuote ? (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
          <div className="engage-card space-y-3">
            <ErrorNote error={error} />
            <div className="grid grid-cols-2 gap-3">
              <div><div className="engage-label">Holder (my.aicountly uuid)</div><input className="engage-input font-mono" value={f.holder_uuid} onChange={set('holder_uuid')} /></div>
              <div><div className="engage-label">or Manage company (cmp_id)</div><input className="engage-input" inputMode="numeric" value={f.cmp_id} onChange={set('cmp_id')} /></div>
            </div>
            <div><div className="engage-label">Offer</div>
              <select className="engage-input" value={f.offer_code} onChange={set('offer_code')}>
                <option value="">Choose…</option>
                {(offers.data || []).map((o) => <option key={o.id} value={o.offer_code}>{o.label} · {o.offer_code} · {formatPaise(o.price_paise)} {o.price_basis === 'inclusive' ? 'incl.' : '+'} GST</option>)}
              </select></div>
            <div className="grid grid-cols-3 gap-3">
              <div><div className="engage-label">Coupon code (optional)</div><input className="engage-input font-mono uppercase" value={f.coupon_code} onChange={set('coupon_code')} /></div>
              <div><div className="engage-label">Purchase</div>
                <select className="engage-input" value={f.kind} onChange={set('kind')}><option value="new">New / upgrade</option><option value="renewal">Renewal</option></select></div>
              <div><div className="engage-label">Lead id (optional)</div><input className="engage-input" inputMode="numeric" value={f.lead_id} onChange={set('lead_id')} /></div>
            </div>
            <div className="text-xs text-neutral-500">With a lead, a seller-owned coupon attributes the lead to that seller unless another seller's 90-day protection applies.</div>
            <div className="flex justify-end"><button className="engage-btn-primary" disabled={make.isPending} onClick={() => make.mutate()}>Create quote</button></div>
          </div>
          {result ? <Breakdown q={result} /> : <div className="engage-card text-sm text-neutral-500">The quote appears here: list, discount, net, GST and total, and the quote ref to use on the licence.</div>}
        </div>
      ) : null}
      <div className="engage-card p-0 overflow-x-auto">
        <table className="engage-table">
          <thead><tr><th>Quote</th><th>Customer</th><th>Offer</th><th>Coupon</th><th className="text-right">List</th><th className="text-right">Discount</th><th className="text-right">Total</th><th>Status</th><th>Licence</th></tr></thead>
          <tbody>
            {(list.data || []).map((q) => (
              <tr key={q.quote_ref} className="cursor-pointer" onClick={() => setResult(q)}>
                <td className="font-mono text-xs">{q.quote_ref}<div className="text-[10px] text-neutral-400">{q.source} · {formatDateIN(q.created_at)}</div></td>
                <td className="font-mono text-xs">{q.holder_uuid || `cmp ${q.cmp_id}`}</td>
                <td className="text-xs">{q.offer.label}</td>
                <td className="font-mono text-xs">{q.coupon ? displayCode(q.coupon.code) : '—'}</td>
                <td className="text-right text-xs">{formatPaise(q.list_paise)}</td>
                <td className="text-right text-xs">{q.discount_paise ? formatPaise(q.discount_paise) : '—'}</td>
                <td className="text-right text-xs">{formatPaise(q.total_paise)}</td>
                <td className="text-xs">{q.status}</td>
                <td className="text-xs">{q.licence_ref ? <Link className="text-aicountly-700 hover:underline" to={`/licences/${q.licence_ref}`}>{q.licence_ref}</Link> : '—'}</td>
              </tr>
            ))}
            {(list.data || []).length === 0 ? <tr><td colSpan={9} className="text-xs text-neutral-500">No quotes yet.</td></tr> : null}
          </tbody>
        </table>
      </div>
    </>
  )
}
