import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import Drawer from './Drawer.jsx'
import { ErrorNote, OfferStatusBadge, describeError } from './LicensingBits.jsx'
import { formatDateIN, formatPaise, paiseToRupees, rupeesToPaise, termText } from '../lib/licensing.js'

const IST = 'Asia/Kolkata'

/** Tomorrow in IST as YYYY-MM-DD — the default effective date of a price revision. */
export function tomorrowIST() {
  const d = new Date(Date.now() + 24 * 3600 * 1000)
  return new Intl.DateTimeFormat('en-CA', { timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)
}

/** GST on a price as Finance bills it (integer paise, round half up) — for the preview only; Engage decides. */
export function gstBreakdown(paise, rate, basis) {
  const bp = Math.round(Number(rate) * 100)
  if (paise === null || !Number.isFinite(bp) || bp < 0) return null
  const roundDiv = (num, den) => Math.floor((num * 2 + den) / (den * 2))
  if (basis === 'inclusive') {
    const gst = roundDiv(paise * bp, 10000 + bp)
    return { taxable: paise - gst, gst, total: paise }
  }
  const gst = roundDiv(paise * bp, 10000)
  return { taxable: paise, gst, total: paise + gst }
}

export const CHANNELS = [
  { code: 'website', label: 'Website', help: 'Listed on www.aicountly.com against the product' },
  { code: 'manage', label: 'Manage', help: 'In the customer\'s plan catalogue in Manage (a Basic always is)' },
  { code: 'seller', label: 'Seller portal', help: 'Sellers can buy it in seller.aicountly.com (priced offers only)' },
]

export function ChannelChips({ channels = [] }) {
  if (!channels.length) return <span className="engage-pill bg-neutral-100 text-neutral-600 border-neutral-200">Staff only</span>
  return (
    <span className="inline-flex flex-wrap gap-1">
      {CHANNELS.filter((c) => channels.includes(c.code)).map((c) => (
        <span key={c.code} className="engage-pill bg-sky-50 text-sky-800 border-sky-200">{c.label}</span>
      ))}
    </span>
  )
}

/**
 * Revise the price of the version on sale "with effect from" a date. A future date schedules the new version: the
 * current price stays on sale until 00:00 IST that day. Today publishes it at once.
 */
export function RevisePriceDrawer({ offer, onClose, onDone }) {
  const [form, setForm] = useState(null)
  const [error, setError] = useState(null)
  useEffect(() => {
    setError(null)
    setForm(offer ? { price: paiseToRupees(offer.price_paise), price_basis: offer.price_basis, gst_rate: offer.gst_rate, effective_from: tomorrowIST(), note: '' } : null)
  }, [offer])
  const save = useMutation({
    mutationFn: (f) => {
      const price = rupeesToPaise(f.price)
      if (price === null) throw new Error('Enter the new price in rupees, e.g. 14160 or 14160.50.')
      return api.post(`/v1/offers/${offer.id}/revise`, { price_paise: price, price_basis: f.price_basis, gst_rate: f.gst_rate, effective_from: f.effective_from, note: f.note })
        .then((r) => r.data?.data)
    },
    onSuccess: (d) => onDone?.(d),
    onError: (e) => setError(describeError(e)),
  })
  const preview = useMemo(() => (form ? gstBreakdown(rupeesToPaise(form.price), form.gst_rate, form.price_basis) : null), [form])
  const now = offer ? gstBreakdown(offer.price_paise, offer.gst_rate, offer.price_basis) : null
  if (!offer || !form) return null
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const immediate = form.effective_from && form.effective_from <= new Intl.DateTimeFormat('en-CA', { timeZone: IST }).format(new Date())

  return (
    <Drawer open title={`Revise price — ${offer.offer_code} (v${offer.version})`} onClose={onClose} size="md"
      footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate(form)}>{immediate ? 'Change the price now' : 'Schedule the revision'}</button>}>
      <ErrorNote error={error} />
      <div className="text-sm text-neutral-600">
        On sale now: <b>{formatPaise(offer.price_paise)}</b> ({offer.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {offer.gst_rate}%)
        {now ? <> — customer pays {formatPaise(now.total)}</> : null}. Term {termText(offer.term_months, offer.term_days)}.
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><div className="engage-label">New price (₹)</div><input className="engage-input" inputMode="decimal" value={form.price} onChange={set('price')} /></div>
        <div><div className="engage-label">Price is</div>
          <select className="engage-input" value={form.price_basis} onChange={set('price_basis')}>
            <option value="inclusive">Inclusive of GST</option><option value="exclusive">Exclusive of GST</option>
          </select></div>
        <div><div className="engage-label">GST rate (%)</div><input className="engage-input" inputMode="decimal" value={form.gst_rate} onChange={set('gst_rate')} /></div>
        <div><div className="engage-label">With effect from (00:00 IST)</div><input className="engage-input" type="date" value={form.effective_from} onChange={set('effective_from')} /></div>
      </div>
      {preview ? (
        <div className="text-xs text-neutral-600 rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2">
          From {form.effective_from ? formatDateIN(`${form.effective_from}T00:00:00+05:30`) : '—'}: taxable {formatPaise(preview.taxable)} + GST {formatPaise(preview.gst)} = <b>{formatPaise(preview.total)}</b> per licence.
        </div>
      ) : null}
      <div><div className="engage-label">Why the price changes (staff see it in the price history)</div>
        <textarea className="engage-input" rows={3} value={form.note} onChange={set('note')} placeholder="e.g. Annual price revision FY 2027-28" /></div>
      <div className="text-xs text-neutral-500">
        {immediate
          ? 'Today: the new version goes on sale at once and the current one is retired.'
          : 'The current price stays on sale until that date; from 00:00 IST the new price applies to new sales, renewals, the website, Manage and sellers. Existing licences keep their price until they renew; open quotes and unpaid seller orders keep theirs until they expire. You can cancel the revision until it takes effect.'}
      </div>
    </Drawer>
  )
}

/** Every version of an offer with the period its price applied. */
export function PriceHistoryDrawer({ code, onClose }) {
  const q = useQuery({ queryKey: ['offers', 'price-history', code], enabled: !!code, queryFn: () => api.get(`/v1/offers/price-history/${code}`).then((r) => r.data?.data) })
  if (!code) return null
  const versions = (q.data?.versions || []).filter((v) => v.status !== 'draft')
  return (
    <Drawer open title={`Price history — ${code}`} onClose={onClose} size="xl">
      {q.isLoading ? <div className="text-sm text-neutral-500">Loading…</div> : null}
      {q.data?.scheduled ? <div className="rounded-md bg-sky-50 border border-sky-200 text-sky-900 text-sm px-3 py-2">v{q.data.scheduled.version} takes effect on {formatDateIN(q.data.scheduled.effective_from, { withTime: true })}.</div> : null}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs text-neutral-500 border-b">
            <th className="py-2 pr-3">Version</th><th className="pr-3">Price</th><th className="pr-3">Customer pays</th><th className="pr-3">Applied</th><th className="pr-3">Why</th>
          </tr></thead>
          <tbody>
            {versions.map((v) => {
              const g = gstBreakdown(v.price_paise, v.gst_rate, v.price_basis)
              return (
                <tr key={v.id} className="border-b border-neutral-100 align-top">
                  <td className="py-2 pr-3"><span className="font-mono text-xs">v{v.version}</span> <OfferStatusBadge status={v.status} />{v.on_sale_now ? <div className="text-xs text-green-700 mt-1">On sale now</div> : null}</td>
                  <td className="pr-3">{formatPaise(v.price_paise)}<div className="text-xs text-neutral-500">{v.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {v.gst_rate}%</div></td>
                  <td className="pr-3">{g ? formatPaise(g.total) : '—'}</td>
                  <td className="pr-3 text-xs">{v.was_on_sale || v.status === 'scheduled' ? <>{formatDateIN(v.effective_from, { withTime: true })} → {v.effective_to ? formatDateIN(v.effective_to, { withTime: true }) : 'now'}</> : 'Never on sale'}</td>
                  <td className="pr-3 text-xs text-neutral-600">{v.revision_note || '—'}{v.retired_reason ? <div className="text-neutral-400">{v.retired_reason}</div> : null}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Drawer>
  )
}

/** Where this version is sold. Visibility, not terms: changeable on a published version. */
export function ChannelsDrawer({ offer, onClose, onDone }) {
  const [sel, setSel] = useState([])
  const [error, setError] = useState(null)
  useEffect(() => { setSel(offer?.channels || []); setError(null) }, [offer])
  const save = useMutation({
    mutationFn: () => api.post(`/v1/offers/${offer.id}/channels`, { channels: sel }).then((r) => r.data?.data),
    onSuccess: (d) => onDone?.(d),
    onError: (e) => setError(describeError(e)),
  })
  if (!offer) return null
  return (
    <Drawer open title={`Where ${offer.offer_code} v${offer.version} is sold`} onClose={onClose} size="md"
      footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>Save channels</button>}>
      <ErrorNote error={error} />
      {CHANNELS.map((c) => (
        <label key={c.code} className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={sel.includes(c.code)} onChange={(e) => setSel(e.target.checked ? [...sel, c.code] : sel.filter((x) => x !== c.code))} />
          <span><b>{c.label}</b><div className="text-xs text-neutral-500">{c.help}</div></span>
        </label>
      ))}
      <div className="text-xs text-neutral-500">None ticked: staff can still issue it, nobody else sees it. A new version or price revision copies these.</div>
    </Drawer>
  )
}
