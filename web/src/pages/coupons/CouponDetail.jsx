import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import clsx from 'clsx'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { TONE_CLASSES, formatDateIN, formatPaise, lastDayIN } from '../../lib/licensing.js'
import { COUPON_ADMIN, COUPON_VIEW, REDEMPTION_TONES, displayCode, ruleText } from '../../lib/coupons.js'
import CouponForm from './CouponForm.jsx'
import { CouponStatusBadge } from './Coupons.jsx'

/** Download the codes CSV (the API needs the bearer token, so fetch it as a blob). Audited server-side. */
async function downloadCsv(id, ref, status) {
  const r = await api.get(`/v1/coupons/${id}/codes.csv`, { params: { status }, responseType: 'blob' })
  const url = URL.createObjectURL(r.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `${String(ref).toLowerCase()}-codes.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

export function RedemptionState({ state }) {
  return <span className={clsx('engage-pill', TONE_CLASSES[REDEMPTION_TONES[state]] || TONE_CLASSES.neutral)}>{state}</span>
}

export function RedemptionsTable({ items, canEdit, onAction }) {
  return (
    <table className="engage-table">
      <thead><tr><th>#</th><th>State</th><th>Customer</th><th>Offer</th><th>Quote / licence</th><th className="text-right">List</th><th className="text-right">Discount</th><th className="text-right">Net</th><th>Seller</th><th /></tr></thead>
      <tbody>
        {items.map((r) => (
          <tr key={r.id}>
            <td className="text-xs">{r.id}</td>
            <td><RedemptionState state={r.state} />{r.state === 'reserved' ? <div className="text-[10px] text-neutral-500">until {formatDateIN(r.reserved_until)}</div> : null}</td>
            <td className="text-xs font-mono">{r.holder_uuid || `cmp ${r.cmp_id}`}</td>
            <td className="text-xs">{r.offer_code} v{r.offer_version}</td>
            <td className="text-xs font-mono">{r.quote_ref}{r.licence_ref ? <> · <Link className="text-aicountly-700 hover:underline" to={`/licences/${r.licence_ref}`}>{r.licence_ref}</Link></> : null}</td>
            <td className="text-right text-xs">{formatPaise(r.list_paise)}</td>
            <td className="text-right text-xs">{formatPaise(r.discount_paise)}</td>
            <td className="text-right text-xs">{formatPaise(r.net_paise)}</td>
            <td className="text-xs">{r.seller?.seller_code || '—'}</td>
            <td>
              {canEdit && r.state === 'reserved' ? <button className="engage-btn-secondary text-xs" onClick={() => { const reason = window.prompt('Release this reservation — reason:'); if (reason) onAction(r.id, 'release', reason) }}>Release</button> : null}
              {canEdit && r.state === 'committed' ? <button className="engage-btn-danger text-xs" onClick={() => { const reason = window.prompt('Reverse this redemption (e.g. invoice credited in full) — reason:'); if (reason) onAction(r.id, 'reverse', reason) }}>Reverse</button> : null}
            </td>
          </tr>
        ))}
        {items.length === 0 ? <tr><td colSpan={10} className="text-xs text-neutral-500">No redemptions yet.</td></tr> : null}
      </tbody>
    </table>
  )
}

export default function CouponDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(COUPON_ADMIN)
  const canExport = hasRole(COUPON_VIEW)
  const [editing, setEditing] = useState(false)
  const [error, setError] = useState(null)
  const [note, setNote] = useState(null)
  const [gen, setGen] = useState({ code: '', count: '100', prefix: '' })
  const [codeStatus, setCodeStatus] = useState('')
  const c = useQuery({ queryKey: ['coupon', id], queryFn: () => api.get(`/v1/coupons/${id}`).then((r) => r.data?.data) })
  const codes = useQuery({ queryKey: ['coupon', id, 'codes', codeStatus], queryFn: () => api.get(`/v1/coupons/${id}/codes`, { params: { limit: 200, ...(codeStatus ? { status: codeStatus } : {}) } }).then((r) => r.data?.data) })
  const reds = useQuery({ queryKey: ['coupon', id, 'redemptions'], queryFn: () => api.get('/v1/coupon-redemptions', { params: { coupon_id: id } }).then((r) => r.data?.data?.items || []) })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['coupon', id] }); qc.invalidateQueries({ queryKey: ['coupons'] }) }
  const fail = (e) => setError(describeError(e))
  const act = useMutation({
    mutationFn: ({ action, body }) => (action === 'discard' ? api.delete(`/v1/coupons/${id}`) : api.post(`/v1/coupons/${id}/${action}`, body || {})).then((r) => r.data?.data),
    onSuccess: (_, v) => { setError(null); if (v.action === 'discard') nav('/coupons'); else refresh() },
    onError: fail,
  })
  const addCodes = useMutation({
    mutationFn: (body) => api.post(`/v1/coupons/${id}/codes`, body).then((r) => r.data?.data),
    onSuccess: (d) => { setError(null); setNote(d.codes ? `Code added: ${d.codes.join(', ')}` : `${d.count} codes generated (batch ${d.batch_ref}).`); setGen({ ...gen, code: '' }); refresh() },
    onError: fail,
  })
  const voidCode = useMutation({ mutationFn: (codeId) => api.post(`/v1/coupons/${id}/codes/${codeId}/void`), onSuccess: refresh, onError: fail })
  const redAct = useMutation({
    mutationFn: ({ rid, action, reason }) => api.post(`/v1/coupon-redemptions/${rid}/${action}`, { reason }),
    onSuccess: refresh,
    onError: fail,
  })
  const d = c.data
  if (c.isLoading) return <div className="text-sm text-neutral-500">Loading…</div>
  if (!d) return <div className="text-sm text-neutral-500">Coupon not found.</div>
  const live = ['draft', 'active', 'paused'].includes(d.status)

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-2">{d.label} <CouponStatusBadge status={d.status} /></span>}
        subtitle={<><span className="font-mono">{d.coupon_ref}</span> · {ruleText(d, formatPaise)} · {d.code_kind === 'shared' ? 'shared code' : 'single-use codes'}</>}
        actions={canEdit ? <>
          {d.status === 'draft' ? <>
            <button className="engage-btn-secondary" onClick={() => setEditing(true)}>Edit</button>
            <button className="engage-btn-secondary" onClick={() => { if (window.confirm('Discard this draft?')) act.mutate({ action: 'discard' }) }}>Discard</button>
            <button className="engage-btn-primary" onClick={() => { if (window.confirm('Approve and activate? The rule becomes immutable and the codes start working. (You cannot approve a coupon you created unless you are an administrator.)')) act.mutate({ action: 'activate', body: { version: d.version } }) }}>Approve & activate</button>
          </> : null}
          {d.status === 'active' ? <button className="engage-btn-secondary" onClick={() => act.mutate({ action: 'pause', body: { version: d.version } })}>Pause</button> : null}
          {d.status === 'paused' ? <button className="engage-btn-primary" onClick={() => act.mutate({ action: 'activate', body: { version: d.version } })}>Resume</button> : null}
          {d.status !== 'retired' && d.status !== 'draft' ? <button className="engage-btn-danger" onClick={() => { const reason = window.prompt('Retire this coupon (final) — reason:'); if (reason) act.mutate({ action: 'retire', body: { version: d.version, reason } }) }}>Retire</button> : null}
        </> : null}
      />
      <ErrorNote error={error} />
      {note ? <div className="mb-3 rounded-md bg-sky-50 border border-sky-200 text-sky-900 text-sm px-3 py-2">{note}</div> : null}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card text-sm space-y-1 lg:col-span-1">
          <div className="font-semibold mb-1">Rule</div>
          <div><span className="text-neutral-400">Discount:</span> {ruleText(d, formatPaise)}</div>
          <div><span className="text-neutral-400">Minimum list price:</span> {d.min_order_paise !== null ? formatPaise(d.min_order_paise) : '—'}</div>
          <div><span className="text-neutral-400">Products:</span> {d.applies_to.product_codes.join(', ') || 'any'}</div>
          <div><span className="text-neutral-400">Offers:</span> {d.applies_to.offer_codes.join(', ') || 'any'}</div>
          <div><span className="text-neutral-400">Licence types:</span> {d.applies_to.licence_type_codes.join(', ') || 'any'}</div>
          <div><span className="text-neutral-400">For:</span> {d.new_or_renewal === 'any' ? 'new and renewal' : d.new_or_renewal}</div>
          <div><span className="text-neutral-400">Valid:</span> {d.valid_from ? formatDateIN(d.valid_from) : 'now'} → {d.valid_to ? lastDayIN(d.valid_to) : 'open'}</div>
          <div><span className="text-neutral-400">Limits:</span> {d.max_redemptions_total ?? 'no'} total · {d.max_redemptions_per_holder ?? 'no'} per customer</div>
          <div><span className="text-neutral-400">Seller:</span> {d.seller ? <Link className="text-aicountly-700 hover:underline" to={`/sellers/${d.seller.id}`}>{d.seller.seller_code}</Link> : '—'}</div>
          <div><span className="text-neutral-400">Campaign:</span> {d.campaign_ref || '—'}</div>
          <div className="pt-2 text-xs text-neutral-500">Created by {d.created_by_email || '—'}{d.approved_at ? ` · approved by ${d.approved_by_email || '—'} on ${formatDateIN(d.approved_at)}` : ''}</div>
          {d.description ? <div className="pt-2 text-xs text-neutral-600 whitespace-pre-wrap">{d.description}</div> : null}
        </div>
        <div className="engage-card text-sm lg:col-span-2">
          <div className="font-semibold mb-2">Usage</div>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div><div className="text-xs text-neutral-400">Used (committed)</div><div className="text-lg font-semibold">{d.stats.committed}</div></div>
            <div><div className="text-xs text-neutral-400">Held by quotes</div><div className="text-lg font-semibold">{d.stats.reserved}</div></div>
            <div><div className="text-xs text-neutral-400">Discount given</div><div className="text-lg font-semibold">{formatPaise(d.stats.discount_committed_paise)}</div></div>
            <div><div className="text-xs text-neutral-400">Net invoiced</div><div className="text-lg font-semibold">{formatPaise(d.stats.net_committed_paise)}</div></div>
          </div>
        </div>
      </div>

      <div className="engage-card mt-4 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="font-semibold">Codes <span className="text-xs text-neutral-500">({d.stats.codes_available} of {d.stats.codes} available)</span></div>
          <div className="flex gap-2">
            <select className="engage-input w-36 text-xs" value={codeStatus} onChange={(e) => setCodeStatus(e.target.value)}>
              <option value="">All codes</option><option value="available">Available</option><option value="reserved">Reserved</option><option value="redeemed">Redeemed</option><option value="void">Void</option>
            </select>
            {canExport ? <button className="engage-btn-secondary text-xs" onClick={() => downloadCsv(d.id, d.coupon_ref, 'available').then(refresh).catch(fail)}>Export available codes (CSV)</button> : null}
          </div>
        </div>
        {canEdit && live ? (
          d.code_kind === 'shared' ? (
            <div className="flex flex-wrap gap-2 items-end">
              <div><div className="engage-label">Add a shared code</div><input className="engage-input font-mono uppercase w-56" placeholder="DIWALI26 (empty = generate)" value={gen.code} onChange={(e) => setGen({ ...gen, code: e.target.value })} /></div>
              <button className="engage-btn-primary" disabled={addCodes.isPending} onClick={() => addCodes.mutate({ code: gen.code })}>{gen.code ? 'Add code' : 'Generate code'}</button>
            </div>
          ) : (
            <div className="flex flex-wrap gap-2 items-end">
              <div><div className="engage-label">How many (1–5000)</div><input className="engage-input w-32" type="number" min={1} max={5000} value={gen.count} onChange={(e) => setGen({ ...gen, count: e.target.value })} /></div>
              <div><div className="engage-label">Prefix (optional)</div><input className="engage-input font-mono uppercase w-32" maxLength={8} value={gen.prefix} onChange={(e) => setGen({ ...gen, prefix: e.target.value })} /></div>
              <button className="engage-btn-primary" disabled={addCodes.isPending} onClick={() => addCodes.mutate({ count: Number(gen.count), prefix: gen.prefix })}>Generate codes</button>
              <div className="text-xs text-neutral-500 basis-full">Codes use A–Z and 2–9 without 0, O, 1 and I: prefix + 10 random characters + 2 check characters. Export them as CSV to distribute.</div>
            </div>
          )
        ) : null}
        <div className="overflow-x-auto max-h-96">
          <table className="engage-table">
            <thead><tr><th>Code</th><th>Status</th><th>Batch</th><th>Exported</th><th /></tr></thead>
            <tbody>
              {(codes.data?.items || []).map((k) => (
                <tr key={k.id}>
                  <td className="font-mono text-xs">{displayCode(k.code)}</td>
                  <td className="text-xs">{k.status}</td>
                  <td className="text-xs font-mono">{k.batch_ref || '—'}</td>
                  <td className="text-xs">{k.exported_at ? `${formatDateIN(k.exported_at)} (${k.export_count}×)` : '—'}</td>
                  <td>{canEdit && k.status === 'available' ? <button className="engage-btn-secondary text-xs" onClick={() => { if (window.confirm(`Void ${displayCode(k.code)}?`)) voidCode.mutate(k.id) }}>Void</button> : null}</td>
                </tr>
              ))}
              {(codes.data?.items || []).length === 0 ? <tr><td colSpan={5} className="text-xs text-neutral-500">No codes yet.</td></tr> : null}
            </tbody>
          </table>
          {codes.data && codes.data.total > (codes.data.items || []).length ? <div className="text-xs text-neutral-500 mt-1">Showing {codes.data.items.length} of {codes.data.total}; export the CSV for all.</div> : null}
        </div>
      </div>

      <div className="engage-card mt-4">
        <div className="font-semibold mb-2">Redemptions</div>
        <RedemptionsTable items={reds.data || []} canEdit={canEdit} onAction={(rid, action, reason) => redAct.mutate({ rid, action, reason })} />
      </div>
      {editing ? <CouponForm open coupon={d} onClose={() => setEditing(false)} onSaved={() => setEditing(false)} /> : null}
    </>
  )
}
