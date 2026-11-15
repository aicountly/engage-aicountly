import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import Drawer from '../../components/Drawer.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { formatDateIN, formatPaise, paiseToRupees, todayIST } from '../../lib/licensing.js'
import { useAuth } from '../../lib/auth.jsx'
import { SELLER_ORDER_ADMIN } from '../../lib/sellerRoles.js'

const STATUSES = ['awaiting_payment', 'paid', 'expired', 'cancelled']

/**
 * Licences sellers bought (docs/SELLING_LICENCES.md §7). An order is paid by bank transfer / UPI quoting its reference
 * until Finance takes licence payments online: a sales manager confirms it here with the UTR, Finance's invoice number
 * and the exact amount, and the seller's stock appears. Unused licences can be withdrawn (e.g. refunded).
 */
export default function SellerLicenceOrders() {
  const [params] = useSearchParams()
  const [f, setF] = useState({ q: '', status: '', seller_id: params.get('seller_id') || '' })
  const [open, setOpen] = useState(null)
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()
  const q = useQuery({ queryKey: ['seller-licence-orders', qs], queryFn: () => api.get(`/v1/seller-licence-orders?limit=100&${qs}`).then((r) => r.data?.data) })

  return (
    <>
      <PageHeader title="Licence orders" subtitle="Licences sellers bought to apply to their customers. Stock is created only from a confirmed payment." />
      <div className="engage-card mb-3 flex flex-wrap gap-2 items-end">
        <div><div className="engage-label">Search</div><input className="engage-input" value={f.q} placeholder="SLO ref, buyer, UTR, invoice…" onChange={(e) => setF({ ...f, q: e.target.value })} /></div>
        <div><div className="engage-label">Status</div>
          <select className="engage-input" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
            <option value="">All</option>{STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select></div>
        {f.seller_id ? <button className="engage-btn-secondary text-xs" onClick={() => setF({ ...f, seller_id: '' })}>Seller #{f.seller_id} ✕</button> : null}
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyMessage="No licence orders yet. Sellers buy licences in seller.aicountly.com once their KYC is verified."
        columns={[
          { key: 'order_ref', header: 'Order', render: (r) => <button className="font-mono text-xs text-aicountly-700 hover:underline" onClick={() => setOpen(r.order_ref)}>{r.order_ref}</button> },
          { key: 'seller', header: 'Seller', render: (r) => r.seller ? <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller.id}`}>{r.seller.organization || r.seller.name}<div className="font-mono text-xs text-neutral-500">{r.seller.seller_code}</div></Link> : '—' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'unit_count', header: 'Licences', render: (r) => <div>{r.unit_count}<div className="text-xs text-neutral-500">{r.units.applied} applied · {r.units.available} in stock</div></div> },
          { key: 'total_paise', header: 'Total', render: (r) => formatPaise(r.total_paise) },
          { key: 'payment', header: 'Payment', render: (r) => r.payment_ref ? <div className="text-xs">{r.payment_ref}<div className="text-neutral-500">{r.invoice_no || '—'} · {r.settled_via}</div></div> : <span className="text-xs text-neutral-500">{r.payment.mode === 'link' ? 'Payment link' : 'Bank transfer'}</span> },
          { key: 'created_at', header: 'Placed', render: (r) => formatDateIN(r.created_at, { withTime: true }) },
        ]}
      />
      <OrderDrawer orderRef={open} onClose={() => setOpen(null)} />
    </>
  )
}

function OrderDrawer({ orderRef, onClose }) {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canAdmin = hasRole(SELLER_ORDER_ADMIN)
  const [pay, setPay] = useState(null)
  const [error, setError] = useState(null)
  const q = useQuery({ queryKey: ['seller-licence-order', orderRef], enabled: !!orderRef, queryFn: () => api.get(`/v1/seller-licence-orders/${orderRef}`).then((r) => r.data?.data) })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['seller-licence-order', orderRef] }); qc.invalidateQueries({ queryKey: ['seller-licence-orders'] }) }
  const act = useMutation({
    mutationFn: ({ path, body }) => api.post(path, body).then((r) => r.data?.data),
    onSuccess: () => { setError(null); setPay(null); refresh() },
    onError: (e) => setError(describeError(e)),
  })
  if (!orderRef) return null
  const o = q.data
  const payable = o && ['awaiting_payment', 'expired'].includes(o.status)

  return (
    <Drawer open title={`Licence order ${orderRef}`} onClose={onClose} size="xl">
      <ErrorNote error={error} />
      {!o ? <div className="text-sm text-neutral-500">Loading…</div> : <>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          <div><div className="engage-label">Status</div><StatusBadge status={o.status} /></div>
          <div><div className="engage-label">Total</div>{formatPaise(o.total_paise)}<div className="text-xs text-neutral-500">taxable {formatPaise(o.taxable_paise)} + GST {formatPaise(o.gst_paise)}</div></div>
          <div><div className="engage-label">Buyer (invoice)</div>{o.buyer.legal_name}<div className="text-xs text-neutral-500">{o.buyer.gstin || 'Not GST-registered'} · state {o.buyer.state_code}</div></div>
          <div><div className="engage-label">Seller</div>{o.seller ? <Link className="text-aicountly-700 hover:underline" to={`/sellers/${o.seller.id}`}>{o.seller.seller_code}</Link> : '—'}</div>
        </div>
        <table className="w-full text-sm mt-2">
          <thead><tr className="text-left text-xs text-neutral-500 border-b"><th className="py-2">Licence</th><th>Qty</th><th>Unit price</th><th>Line (as invoiced)</th></tr></thead>
          <tbody>{o.lines.map((l) => (
            <tr key={l.offer_code} className="border-b border-neutral-100"><td className="py-2">{l.label} <span className="font-mono text-xs text-neutral-500">{l.offer_code} v{l.offer_version}</span><div className="text-xs text-neutral-500">{l.term || 'No end date'}</div></td>
              <td>{l.quantity}</td><td>{formatPaise(l.unit_price_paise)} <span className="text-xs text-neutral-500">{l.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {l.gst_rate}%</span></td>
              <td>{formatPaise(l.total_paise)}<div className="text-xs text-neutral-500">taxable {formatPaise(l.taxable_paise)} + GST {formatPaise(l.gst_paise)}</div></td></tr>
          ))}</tbody>
        </table>
        {o.payment_ref ? (
          <div className="text-sm rounded-md bg-green-50 border border-green-200 px-3 py-2">Paid {formatPaise(o.amount_paid_paise)} — {o.payment_ref} · invoice {o.invoice_no || '—'} {o.invoice_date ? `(${o.invoice_date})` : ''} · {formatDateIN(o.paid_at, { withTime: true })} · via {o.settled_via}</div>
        ) : null}
        {o.link_error ? <div className="text-xs text-amber-800">Payment link: {o.link_error}</div> : null}
        {o.cancel_reason ? <div className="text-sm text-neutral-600">Cancelled ({o.cancelled_by_kind}): {o.cancel_reason}</div> : null}

        {payable && canAdmin ? (
          pay ? (
            <div className="engage-card space-y-3">
              <div className="font-medium text-sm">Confirm the payment</div>
              <div className="grid grid-cols-2 gap-3">
                <div><div className="engage-label">UTR / payment reference</div><input className="engage-input" value={pay.payment_ref} onChange={(e) => setPay({ ...pay, payment_ref: e.target.value })} /></div>
                <div><div className="engage-label">Amount received (₹)</div><input className="engage-input" value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} /></div>
                <div><div className="engage-label">Finance invoice number</div><input className="engage-input" value={pay.invoice_no} onChange={(e) => setPay({ ...pay, invoice_no: e.target.value })} /></div>
                <div><div className="engage-label">Invoice date</div><input className="engage-input" type="date" value={pay.invoice_date} onChange={(e) => setPay({ ...pay, invoice_date: e.target.value })} /></div>
              </div>
              <div className="text-xs text-neutral-500">The amount must be the order total ({formatPaise(o.total_paise)}). Record the receipt and the GST invoice in Finance first; the seller's {o.unit_count} licence{o.unit_count === 1 ? '' : 's'} appear in their stock at once.</div>
              <div className="flex gap-2">
                <button className="engage-btn-primary" disabled={act.isPending} onClick={() => act.mutate({ path: `/v1/seller-licence-orders/${o.order_ref}/confirm-payment`, body: pay })}>Confirm payment</button>
                <button className="engage-btn-secondary" onClick={() => setPay(null)}>Cancel</button>
              </div>
            </div>
          ) : (
            <div className="flex gap-2">
              <button className="engage-btn-primary" onClick={() => setPay({ payment_ref: '', amount: paiseToRupees(o.total_paise), invoice_no: '', invoice_date: todayIST() })}>Confirm payment…</button>
              <button className="engage-btn-danger" onClick={() => { const reason = window.prompt(`Cancel ${o.order_ref}? Say why:`); if (reason) act.mutate({ path: `/v1/seller-licence-orders/${o.order_ref}/cancel`, body: { reason } }) }}>Cancel order</button>
            </div>
          )
        ) : null}

        {(o.unit_list || []).length ? (
          <div>
            <div className="engage-label">Licences ({o.unit_list.length})</div>
            <table className="w-full text-sm">
              <thead><tr className="text-left text-xs text-neutral-500 border-b"><th className="py-2">Unit</th><th>Status</th><th>Customer / licence</th><th>Commission</th><th /></tr></thead>
              <tbody>{o.unit_list.map((u) => (
                <tr key={u.unit_ref} className="border-b border-neutral-100 align-top">
                  <td className="py-2 font-mono text-xs">{u.unit_ref}<div className="text-neutral-500">{u.offer_code} v{u.offer_version}</div></td>
                  <td><StatusBadge status={u.status} />{u.apply_kind ? <div className="text-xs text-neutral-500">{u.apply_kind}</div> : null}</td>
                  <td className="text-xs">{u.licence_ref ? <><Link className="font-mono text-aicountly-700 hover:underline" to={`/licences/${u.licence_ref}`}>{u.licence_ref}</Link><div>{u.customer_email}</div>{u.lead_id ? <Link className="text-aicountly-700 hover:underline" to={`/leads/${u.lead_id}`}>Lead</Link> : null}</> : (u.void_reason || '—')}</td>
                  <td className="text-xs text-neutral-600">{u.commission_note || '—'}</td>
                  <td>{u.status === 'available' && canAdmin ? <button className="engage-btn-secondary text-xs" onClick={() => { const reason = window.prompt(`Withdraw ${u.unit_ref} from the seller's stock? Say why (e.g. refunded):`); if (reason) act.mutate({ path: `/v1/seller-licence-units/${u.unit_ref}/void`, body: { reason } }) }}>Withdraw</button> : null}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : null}
      </>}
    </Drawer>
  )
}
