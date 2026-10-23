import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import DataTable from '../../components/DataTable.jsx'
import { ErrorNote, LicenceStatusBadge, LicenceTimeline, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import clsx from 'clsx'
import { LICENSING_ADMIN, OUTBOX_TONES, TONE_CLASSES, formatDateIN, formatPaise, lastDayIN } from '../../lib/licensing.js'

// What each action needs and which stored statuses allow it (server-php LicenceService::ALLOWED).
const ACTIONS = {
  activate: { label: 'Activate', from: ['issued'], fields: [] },
  renew: { label: 'Renew', from: ['active', 'expired'], fields: ['invoice_refs', 'reason_optional'], help: 'Adds one term of the latest published version of this offer after the current end (or from today if it has ended).' },
  extend: { label: 'Extend', from: ['issued', 'active', 'suspended'], fields: ['ends_at', 'reason'], help: 'Moves the end to a later date (goodwill or correction).' },
  suspend: { label: 'Suspend', from: ['active'], fields: ['reason'], danger: true },
  reinstate: { label: 'Reinstate', from: ['suspended'], fields: ['reason'] },
  'change-offer': { label: 'Change plan', from: ['issued', 'active', 'suspended'], fields: ['offer', 'reason_optional'], help: 'Upgrade or downgrade to another published offer of the same product. Manage receives the new plan automatically. No proration is computed — settle the price difference on the invoice.' },
  revoke: { label: 'Revoke', from: ['issued', 'active', 'suspended', 'expired', 'superseded'], fields: ['reason'], danger: true, help: "Final — a revoked licence cannot be changed again. Revoking a paid plan brings the holder's Basic back." },
  seats: { label: 'Change seats', from: ['issued', 'active', 'suspended'], fields: ['seats_total', 'reason_optional'] },
  'seat-assignments': { label: 'Assign a seat', from: ['issued', 'active'], fields: ['member'] },
  'invoice-refs': { label: 'Link invoice', from: ['issued', 'active', 'suspended', 'expired', 'revoked'], fields: ['invoice_ref'] },
}

export default function LicenceDetail() {
  const { ref } = useParams()
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [form, setForm] = useState(null)
  const [error, setError] = useState(null)
  const q = useQuery({ queryKey: ['licence', ref], queryFn: () => api.get(`/v1/licences/${ref}`).then((r) => r.data?.data) })
  const l = q.data
  const offers = useQuery({
    queryKey: ['offers', 'published'], enabled: form?.action === 'change-offer',
    queryFn: () => api.get('/v1/offers', { params: { status: 'published' } }).then((r) => r.data?.data?.items || []),
  })
  const sync = useQuery({
    queryKey: ['entitlement-outbox', 'licence', ref],
    queryFn: () => api.get('/v1/entitlements/outbox', { params: { licence_ref: ref, limit: 10 } }).then((r) => r.data?.data),
  })
  const run = useMutation({
    mutationFn: ({ action, body }) => api.post(`/v1/licences/${ref}/${action}`, { version: l.version, ...body }).then((r) => r.data?.data),
    onSuccess: (data) => { setForm(null); setError(null); qc.setQueryData(['licence', ref], data); qc.invalidateQueries({ queryKey: ['licences'] }); qc.invalidateQueries({ queryKey: ['entitlement-outbox'] }) },
    onError: (e) => {
      setError(describeError(e))
      if (e?.response?.data?.code === 'version_conflict') qc.invalidateQueries({ queryKey: ['licence', ref] })
    },
  })
  const release = useMutation({
    mutationFn: (seatId) => api.post(`/v1/licences/${ref}/seat-assignments/${seatId}/release`, { version: l.version }).then((r) => r.data?.data),
    onSuccess: (data) => { setError(null); qc.setQueryData(['licence', ref], data) },
    onError: (e) => setError(describeError(e)),
  })

  if (q.isLoading) return <div>Loading…</div>
  if (!l) return <div className="engage-card">Licence not found. <Link className="text-aicountly-700 hover:underline" to="/licences">Back to licences</Link></div>

  const open = (action) => { setError(null); setForm({ action, reason: '', ends_at: '', seats_total: l.seats.total ?? '', unlimited: l.seats.total === null, invoice_refs: '', invoice_ref: '', member_ref: '', member_label: '', offer_code: '', term_mode: 'keep' }) }
  const submit = () => {
    const a = ACTIONS[form.action]
    const body = {}
    if (a.fields.includes('reason') || a.fields.includes('reason_optional')) body.reason = form.reason
    if (a.fields.includes('ends_at')) body.ends_at = form.ends_at
    if (a.fields.includes('seats_total')) body.seats_total = form.unlimited ? null : Number(form.seats_total)
    if (a.fields.includes('invoice_refs')) body.invoice_refs = form.invoice_refs
    if (a.fields.includes('invoice_ref')) body.invoice_ref = form.invoice_ref
    if (a.fields.includes('member')) Object.assign(body, { member_ref: form.member_ref, member_label: form.member_label })
    if (a.fields.includes('offer')) Object.assign(body, { offer_code: form.offer_code, term_mode: form.term_mode })
    run.mutate({ action: form.action, body })
  }
  const available = Object.entries(ACTIONS).filter(([k, a]) => a.from.includes(l.stored_status) && !(k === 'change-offer' && l.is_basic))
  const changeTargets = (offers.data || []).filter((o) => o.product.code === l.product.code && o.offer_code !== l.offer.code && !o.is_default)
  const snap = l.offer_snapshot || {}

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-2"><span className="font-mono">{l.licence_ref}</span> <LicenceStatusBadge status={l.status} /></span>}
        subtitle={[l.offer.label, l.holder_uuid ? `holder ${l.holder_uuid}` : null, l.account?.name, l.cmp_id ? `cmp_id ${l.cmp_id}` : null].filter(Boolean).join(' · ')}
        actions={<Link to="/licences" className="engage-btn-secondary">Back</Link>}
      />
      <ErrorNote error={!form ? error : null} />
      {l.status.code !== l.stored_status ? (
        <div className="mb-3 rounded-md bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">
          The term has ended, so this licence is shown as expired. The expiry job (engage:licences-expire) records it.
        </div>
      ) : null}
      {l.stored_status === 'superseded' ? (
        <div className="mb-3 rounded-md bg-sky-50 border border-sky-200 text-sky-900 text-sm px-3 py-2">
          This Basic plan is paused while a paid plan of the same product is active for this holder. It becomes active again on its own when the paid plan ends.
        </div>
      ) : null}
      {canEdit && available.length ? (
        <div className="mb-4 flex flex-wrap gap-2">
          {available.map(([k, a]) => <button key={k} className={a.danger ? 'engage-btn-danger text-xs' : 'engage-btn-secondary text-xs'} onClick={() => open(k)}>{a.label}</button>)}
        </div>
      ) : null}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card lg:col-span-1 space-y-2 text-sm">
          <Field label="Holder">{l.holder_uuid ? <Link className="font-mono text-xs text-aicountly-700 hover:underline" to={`/licences?holder_uuid=${encodeURIComponent(l.holder_uuid)}`}>{l.holder_uuid}</Link> : <span className="text-neutral-500">— (company licence)</span>}{l.is_basic ? ' · Basic plan' : ''}{l.is_addon ? ' · add-on' : ''}</Field>
          {l.holder_note ? <Field label="Holder verified (internal)">{l.holder_note}</Field> : null}
          <Field label="Manage company">{l.cmp_id ? <span className="font-mono">{l.cmp_id}</span> : '—'}</Field>
          <Field label="Product">{l.product.label}</Field>
          <Field label="Licence type">{l.licence_type.label} <span className="text-xs text-neutral-500">({l.licence_type.lifecycle_kind})</span></Field>
          {l.plan ? <Field label="Plan">{l.plan.label}</Field> : null}
          <Field label="Offer"><span className="font-mono text-xs">{l.offer.code} v{l.offer.version}</span> · {formatPaise(snap.price_paise)} {snap.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {snap.gst_rate}%</Field>
          <Field label="Term">{formatDateIN(l.term.starts_at)} – {lastDayIN(l.term.ends_at)}{l.term.cycle ? ` (${l.term.cycle})` : ''}</Field>
          <Field label="Seats">{l.seats.assigned} assigned of {l.seats.total ?? 'unlimited'}</Field>
          <Field label="Apps"><span className="font-mono text-xs">{(l.app_keys || []).join(', ')}</span></Field>
          <Field label="Invoices">{l.invoice_refs.length ? l.invoice_refs.join(', ') : '—'}</Field>
          <Field label="Source">{l.source === 'staff' ? 'Issued by staff' : l.source === 'provisioning' ? 'Provisioned at sign-up (Basic)' : `Order ${l.order_ref}`}</Field>
          {l.status_reason ? <Field label="Status reason (internal)">{l.status_reason}</Field> : null}
          {l.notes ? <Field label="Notes (internal)">{l.notes}</Field> : null}
          <Field label="Version">{l.version}</Field>
          {l.entitlements.length ? <Field label="Entitlements">{l.entitlements.map((e) => `${e.label}${e.value !== null && e.value !== undefined ? `: ${e.value}${e.unit ? ' ' + e.unit : ''}` : ''}`).join(' · ')}</Field> : null}
        </div>
        <div className="lg:col-span-2 space-y-4">
          <div>
            <div className="text-sm font-semibold mb-2">Seat assignments</div>
            <DataTable
              keyField="seat_id"
              rows={l.seat_assignments}
              emptyMessage="No seats assigned."
              columns={[
                { key: 'member_label', header: 'Member', render: (s) => s.member_label || s.member_ref || 'Unnamed seat' },
                { key: 'member_ref', header: 'Identifier', render: (s) => <span className="font-mono text-xs">{s.member_ref || '—'}</span> },
                { key: 'assigned_at', header: 'Assigned', render: (s) => formatDateIN(s.assigned_at) },
                { key: 'released_at', header: 'Released', render: (s) => (s.released_at ? formatDateIN(s.released_at) : canEdit ? <button className="engage-btn-secondary text-xs" disabled={release.isPending} onClick={() => { if (window.confirm('Release this seat?')) release.mutate(s.seat_id) }}>Release</button> : '—') },
              ]}
            />
          </div>
          <div className="engage-card">
            <div className="flex items-center justify-between mb-2">
              <div className="text-sm font-semibold">Manage sync</div>
              <Link to={`/manage-sync${l.holder_uuid ? `?holder_uuid=${encodeURIComponent(l.holder_uuid)}` : ''}`} className="text-xs text-aicountly-700 hover:underline">All pushes</Link>
            </div>
            {sync.data?.items?.length ? (
              <ul className="text-sm space-y-1">
                {sync.data.items.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center gap-2">
                    <span className={clsx('engage-pill', TONE_CLASSES[OUTBOX_TONES[r.status]] || TONE_CLASSES.neutral)}>{r.status}</span>
                    <span className="font-mono text-xs">{r.app_key} #{r.seq}</span>
                    <span className="text-neutral-600">{r.payload?.status}{r.payload?.plan ? ` · ${r.payload.plan.label}` : ''}</span>
                    <span className="text-xs text-neutral-500">{formatDateIN(r.created_at, { withTime: true })}{r.last_error ? ` · ${r.last_error}` : ''}</span>
                  </li>
                ))}
              </ul>
            ) : <div className="text-sm text-neutral-500">Nothing pushed for this licence yet.</div>}
            {sync.data && !sync.data.manage?.configured ? <div className="text-xs text-amber-700 mt-2">Manage is not connected yet — changes are queued and sent once it is.</div> : null}
          </div>
          <div className="engage-card">
            <div className="text-sm font-semibold mb-3">Activity</div>
            <LicenceTimeline events={l.events} />
            <div className="text-xs text-neutral-500 mt-3">Manage shows the same timeline to the customer, without internal reasons and with “Aicountly team” instead of staff names.</div>
          </div>
        </div>
      </div>
      <Drawer open={!!form} size="md" title={form ? `${ACTIONS[form.action].label} ${l.licence_ref}` : ''} onClose={() => setForm(null)}
        footer={form ? <button className={ACTIONS[form.action].danger ? 'engage-btn-danger' : 'engage-btn-primary'} disabled={run.isPending} onClick={submit}>{ACTIONS[form.action].label}</button> : null}>
        {form ? <>
          <ErrorNote error={error} />
          {ACTIONS[form.action].help ? <div className="text-sm text-neutral-600">{ACTIONS[form.action].help}</div> : null}
          {ACTIONS[form.action].fields.includes('ends_at') ? <div><div className="engage-label">New end date (licence runs until the day before)</div><input className="engage-input" type="date" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></div> : null}
          {ACTIONS[form.action].fields.includes('seats_total') ? <div className="grid grid-cols-2 gap-3 items-end">
            <div><div className="engage-label">Seats</div><input className="engage-input" type="number" min={0} disabled={form.unlimited} value={form.seats_total} onChange={(e) => setForm({ ...form, seats_total: e.target.value })} /></div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.unlimited} onChange={(e) => setForm({ ...form, unlimited: e.target.checked })} /> No seat limit</label>
          </div> : null}
          {ACTIONS[form.action].fields.includes('member') ? <>
            <div><div className="engage-label">Member identifier (optional, e.g. my.aicountly uuid or email)</div><input className="engage-input font-mono" value={form.member_ref} onChange={(e) => setForm({ ...form, member_ref: e.target.value })} /></div>
            <div><div className="engage-label">Member name (optional)</div><input className="engage-input" value={form.member_label} onChange={(e) => setForm({ ...form, member_label: e.target.value })} /></div>
          </> : null}
          {ACTIONS[form.action].fields.includes('offer') ? <>
            <div><div className="engage-label">Move to offer ({l.product.label})</div>
              <select className="engage-input" value={form.offer_code} onChange={(e) => setForm({ ...form, offer_code: e.target.value })}>
                <option value="">Choose…</option>
                {changeTargets.map((o) => <option key={o.id} value={o.offer_code}>{o.label} · {o.offer_code} v{o.version} · {formatPaise(o.price_paise)}</option>)}
              </select>
              {offers.isFetched && changeTargets.length === 0 ? <div className="text-xs text-neutral-500 mt-1">No other published offer of this product.</div> : null}
            </div>
            <div className="flex gap-4 text-sm">
              <label className="flex items-center gap-2"><input type="radio" checked={form.term_mode === 'keep'} onChange={() => setForm({ ...form, term_mode: 'keep' })} /> Keep the end date ({lastDayIN(l.term.ends_at)})</label>
              <label className="flex items-center gap-2"><input type="radio" checked={form.term_mode === 'restart'} onChange={() => setForm({ ...form, term_mode: 'restart' })} /> Start the new offer's term today</label>
            </div>
          </> : null}
          {ACTIONS[form.action].fields.includes('invoice_refs') ? <div><div className="engage-label">Invoice numbers for this renewal (optional)</div><input className="engage-input font-mono" value={form.invoice_refs} onChange={(e) => setForm({ ...form, invoice_refs: e.target.value })} /></div> : null}
          {ACTIONS[form.action].fields.includes('invoice_ref') ? <div><div className="engage-label">Invoice number</div><input className="engage-input font-mono" value={form.invoice_ref} onChange={(e) => setForm({ ...form, invoice_ref: e.target.value })} /></div> : null}
          {ACTIONS[form.action].fields.includes('reason') || ACTIONS[form.action].fields.includes('reason_optional') ? (
            <div>
              <div className="engage-label">Reason{ACTIONS[form.action].fields.includes('reason') ? '' : ' (optional)'} — internal, never shown to the customer</div>
              <textarea className="engage-input" rows={3} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
            </div>
          ) : null}
        </> : null}
      </Drawer>
    </>
  )
}

function Field({ label, children }) {
  return (
    <div>
      <div className="text-xs text-neutral-500">{label}</div>
      <div className="font-medium text-neutral-900">{children}</div>
    </div>
  )
}
