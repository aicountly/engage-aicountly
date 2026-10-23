import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { pickRows } from '../../lib/api.js'
import Drawer from '../../components/Drawer.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { paiseToRupees, rupeesToPaise } from '../../lib/licensing.js'
import { bpFromPercent } from '../../lib/coupons.js'

const dateOnly = (iso, lastDay = false) => {
  if (!iso) return ''
  // valid_to is stored as 00:00 IST the day AFTER the last valid day → show that last day.
  const t = new Date(iso).getTime() - (lastDay ? 1000 : 0)
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date(t))
}
const list = (a) => (a || []).join(', ')

export function couponToForm(c) {
  if (!c) {
    return {
      code_kind: 'shared', label: '', description: '', discount_type: 'percent', percent: '', flat: '', max_discount: '', min_order: '',
      product_codes: '', offer_codes: '', licence_type_codes: '', new_or_renewal: 'any', valid_from: '', valid_to: '',
      max_redemptions_total: '', max_redemptions_per_holder: '', seller_code: '', campaign_ref: '', code: '',
    }
  }
  return {
    id: c.id, version: c.version, code_kind: c.code_kind, label: c.label, description: c.description || '', discount_type: c.discount_type,
    percent: c.percent_bp ? String(c.percent_bp / 100) : '', flat: c.flat_paise ? paiseToRupees(c.flat_paise) : '',
    max_discount: c.max_discount_paise ? paiseToRupees(c.max_discount_paise) : '', min_order: c.min_order_paise !== null ? paiseToRupees(c.min_order_paise) : '',
    product_codes: list(c.applies_to?.product_codes), offer_codes: list(c.applies_to?.offer_codes), licence_type_codes: list(c.applies_to?.licence_type_codes),
    new_or_renewal: c.new_or_renewal, valid_from: dateOnly(c.valid_from), valid_to: dateOnly(c.valid_to, true),
    max_redemptions_total: c.max_redemptions_total ?? '', max_redemptions_per_holder: c.max_redemptions_per_holder ?? '',
    seller_code: c.seller?.seller_code || '', campaign_ref: c.campaign_ref || '', code: '',
  }
}

function toBody(f) {
  const money = (v, label, { allowEmpty = true } = {}) => {
    if (v === '' || v === null || v === undefined) {
      if (allowEmpty) return null
      throw new Error(`${label} is required.`)
    }
    const p = rupeesToPaise(v)
    if (p === null) throw new Error(`${label}: enter rupees, e.g. 500 or 499.50.`)
    return p
  }
  const codes = (s) => String(s || '').split(',').map((x) => x.trim().toLowerCase()).filter(Boolean)
  const body = {
    code_kind: f.code_kind, label: f.label, description: f.description, discount_type: f.discount_type,
    max_discount_paise: f.discount_type === 'percent' ? money(f.max_discount, 'Maximum discount') : null,
    min_order_paise: money(f.min_order, 'Minimum order'),
    applies_to: { product_codes: codes(f.product_codes), offer_codes: codes(f.offer_codes), licence_type_codes: codes(f.licence_type_codes) },
    new_or_renewal: f.new_or_renewal, valid_from: f.valid_from || null, valid_to: f.valid_to || null,
    max_redemptions_total: f.max_redemptions_total === '' ? null : Number(f.max_redemptions_total),
    max_redemptions_per_holder: f.max_redemptions_per_holder === '' ? null : Number(f.max_redemptions_per_holder),
    seller_code: f.seller_code || '', campaign_ref: f.campaign_ref || '',
  }
  if (f.discount_type === 'percent') {
    const bp = bpFromPercent(f.percent)
    if (bp === null) throw new Error('Percentage: 0.01 to 100, at most two decimals.')
    body.percent_bp = bp
  } else {
    body.flat_paise = money(f.flat, 'Amount off', { allowEmpty: false })
  }
  if (!f.id && f.code_kind === 'shared' && f.code) body.code = f.code
  if (f.id) body.version = f.version
  return body
}

/** Create or edit a DRAFT coupon (active coupons are immutable). */
export default function CouponForm({ open, coupon, onClose, onSaved }) {
  const qc = useQueryClient()
  const [f, setF] = useState(() => couponToForm(coupon))
  const [error, setError] = useState(null)
  const products = useQuery({ queryKey: ['products'], enabled: open, queryFn: () => api.get('/v1/products').then(pickRows) })
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => {
      const body = toBody(f)
      return (f.id ? api.put(`/v1/coupons/${f.id}`, body) : api.post('/v1/coupons', body)).then((r) => r.data?.data)
    },
    onSuccess: (d) => { setError(null); qc.invalidateQueries({ queryKey: ['coupons'] }); qc.invalidateQueries({ queryKey: ['coupon'] }); onSaved?.(d) },
    onError: (e) => setError(describeError(e)),
  })

  return (
    <Drawer open={open} size="xl" title={f.id ? `Edit draft ${coupon?.coupon_ref}` : 'New coupon (draft)'} onClose={onClose}
      footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>Save draft</button>}>
      <ErrorNote error={error} />
      <div className="text-xs text-neutral-500">A draft can be edited freely. Activating it is the approval: another sales manager or an administrator
        activates it, and from then on its rule cannot change (retire it and create another).</div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div className="md:col-span-2"><div className="engage-label">Label (internal)</div><input className="engage-input" value={f.label} onChange={set('label')} /></div>
        <div><div className="engage-label">Codes</div>
          <select className="engage-input" value={f.code_kind} onChange={set('code_kind')}>
            <option value="shared">Shared — one code, many customers</option>
            <option value="single_use">Single-use — bulk codes, one use each</option>
          </select></div>
      </div>
      <div><div className="engage-label">Description (internal)</div><textarea className="engage-input" rows={2} value={f.description} onChange={set('description')} /></div>
      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div><div className="engage-label">Discount</div>
          <select className="engage-input" value={f.discount_type} onChange={set('discount_type')}>
            <option value="percent">Percent off</option><option value="flat">Flat ₹ off</option>
          </select></div>
        {f.discount_type === 'percent' ? <>
          <div><div className="engage-label">Percent</div><input className="engage-input" inputMode="decimal" placeholder="20" value={f.percent} onChange={set('percent')} /></div>
          <div><div className="engage-label">Max discount ₹ (optional)</div><input className="engage-input" inputMode="decimal" value={f.max_discount} onChange={set('max_discount')} /></div>
        </> : <div><div className="engage-label">Amount off ₹</div><input className="engage-input" inputMode="decimal" placeholder="500" value={f.flat} onChange={set('flat')} /></div>}
        <div><div className="engage-label">Minimum list price ₹ (optional)</div><input className="engage-input" inputMode="decimal" value={f.min_order} onChange={set('min_order')} /></div>
      </div>
      <div className="text-xs text-neutral-500">The discount comes off the offer price on its own basis (incl. or excl. GST); GST is charged on the price after
        discount. The net never falls below ₹1 — a free plan is a complimentary licence, not a coupon.</div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div><div className="engage-label">Products (codes, comma separated; empty = any)</div>
          <input className="engage-input font-mono" list="coupon-products" value={f.product_codes} onChange={set('product_codes')} />
          <datalist id="coupon-products">{(products.data || []).map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}</datalist></div>
        <div><div className="engage-label">Offer codes (empty = any)</div><input className="engage-input font-mono" value={f.offer_codes} onChange={set('offer_codes')} /></div>
        <div><div className="engage-label">Licence types (empty = any)</div><input className="engage-input font-mono" value={f.licence_type_codes} onChange={set('licence_type_codes')} /></div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div><div className="engage-label">Applies to</div>
          <select className="engage-input" value={f.new_or_renewal} onChange={set('new_or_renewal')}>
            <option value="any">New and renewal</option><option value="new">New purchases only</option><option value="renewal">Renewals only</option>
          </select></div>
        <div><div className="engage-label">Valid from</div><input className="engage-input" type="date" value={f.valid_from} onChange={set('valid_from')} /></div>
        <div><div className="engage-label">Last valid day</div><input className="engage-input" type="date" value={f.valid_to} onChange={set('valid_to')} /></div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div><div className="engage-label">Total uses (empty = no limit)</div><input className="engage-input" type="number" min={1} value={f.max_redemptions_total} onChange={set('max_redemptions_total')} /></div>
        <div><div className="engage-label">Uses per customer</div><input className="engage-input" type="number" min={1} value={f.max_redemptions_per_holder} onChange={set('max_redemptions_per_holder')} /></div>
        <div><div className="engage-label">Seller code (referral coupon)</div><input className="engage-input font-mono" placeholder="SLR-000123" value={f.seller_code} onChange={set('seller_code')} /></div>
        <div><div className="engage-label">Campaign ref</div><input className="engage-input font-mono" value={f.campaign_ref} onChange={set('campaign_ref')} /></div>
      </div>
      {!f.id && f.code_kind === 'shared' ? (
        <div><div className="engage-label">Shared code (4–20 letters / digits; leave empty to generate one later)</div>
          <input className="engage-input font-mono uppercase" placeholder="DIWALI26" value={f.code} onChange={set('code')} /></div>
      ) : null}
    </Drawer>
  )
}
