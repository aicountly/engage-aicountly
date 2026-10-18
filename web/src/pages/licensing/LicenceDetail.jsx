import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import DataTable from '../../components/DataTable.jsx'
import { ErrorNote, LicenceStatusBadge, LicenceTimeline, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN, formatPaise, lastDayIN } from '../../lib/licensing.js'

// What each action needs and which stored statuses allow it (server-php LicenceService::ALLOWED).
const ACTIONS = {
  activate: { label: 'Activate', from: ['issued'], fields: [] },
  renew: { label: 'Renew', from: ['active', 'expired'], fields: ['invoice_refs', 'reason_optional'], help: 'Adds one term of the latest published version of this offer after the current end (or from today if it has ended).' },
  extend: { label: 'Extend', from: ['issued', 'active', 'suspended'], fields: ['ends_at', 'reason'], help: 'Moves the end to a later date (goodwill or correction).' },
  suspend: { label: 'Suspend', from: ['active'], fields: ['reason'], danger: true },
  reinstate: { label: 'Reinstate', from: ['suspended'], fields: ['reason'] },
  revoke: { label: 'Revoke', from: ['issued', 'active', 'suspended', 'expired'], fields: ['reason'], danger: true, help: 'Final — a revoked licence cannot be changed again.' },
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
  const run = useMutation({
    mutationFn: ({ action, body }) => api.post(`/v1/licences/${ref}/${action}`, { version: l.version, ...body }).then((r) => r.data?.data),
    onSuccess: (data) => { setForm(null); setError(null); qc.setQueryData(['licence', ref], data); qc.invalidateQueries({ queryKey: ['licences'] }) },
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

  const open = (action) => { setError(null); setForm({ action, reason: '', ends_at: '', seats_total: l.seats.total ?? '', unlimited: l.seats.total === null, invoice_refs: '', invoice_ref: '', member_ref: '', member_label: '' }) }
  const submit = () => {
    const a = ACTIONS[form.action]
    const body = {}
    if (a.fields.includes('reason') || a.fields.includes('reason_optional')) body.reason = form.reason
    if (a.fields.includes('ends_at')) body.ends_at = form.ends_at
    if (a.fields.includes('seats_total')) body.seats_total = form.unlimited ? null : Number(form.seats_total)
    if (a.fields.includes('invoice_refs')) body.invoice_refs = form.invoice_refs
    if (a.fields.includes('invoice_ref')) body.invoice_ref = form.invoice_ref
    if (a.fields.includes('member')) Object.assign(body, { member_ref: form.member_ref, member_label: form.member_label })
    run.mutate({ action: form.action, body })
  }
  const available = Object.entries(ACTIONS).filter(([, a]) => a.from.includes(l.stored_status))
  const snap = l.offer_snapshot || {}

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-2"><span className="font-mono">{l.licence_ref}</span> <LicenceStatusBadge status={l.status} /></span>}
        subtitle={`${l.offer.label} · ${l.account?.name} · cmp_id ${l.cmp_id}`}
        actions={<Link to="/licences" className="engage-btn-secondary">Back</Link>}
      />
      <ErrorNote error={!form ? error : null} />
      {l.status.code !== l.stored_status ? (
        <div className="mb-3 rounded-md bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">
          The term has ended, so this licence is shown as expired. The expiry job (engage:licences-expire) records it.
        </div>
      ) : null}
      {canEdit && available.length ? (
        <div className="mb-4 flex flex-wrap gap-2">
          {available.map(([k, a]) => <button key={k} className={a.danger ? 'engage-btn-danger text-xs' : 'engage-btn-secondary text-xs'} onClick={() => open(k)}>{a.label}</button>)}
        </div>
      ) : null}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card lg:col-span-1 space-y-2 text-sm">
          <Field label="Product">{l.product.label}</Field>
          <Field label="Licence type">{l.licence_type.label} <span className="text-xs text-neutral-500">({l.licence_type.lifecycle_kind})</span></Field>
          {l.plan ? <Field label="Plan">{l.plan.label}</Field> : null}
          <Field label="Offer"><span className="font-mono text-xs">{l.offer.code} v{l.offer.version}</span> · {formatPaise(snap.price_paise)} {snap.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {snap.gst_rate}%</Field>
          <Field label="Term">{formatDateIN(l.term.starts_at)} – {lastDayIN(l.term.ends_at)}{l.term.cycle ? ` (${l.term.cycle})` : ''}</Field>
          <Field label="Seats">{l.seats.assigned} assigned of {l.seats.total ?? 'unlimited'}</Field>
          <Field label="Apps"><span className="font-mono text-xs">{(l.app_keys || []).join(', ')}</span></Field>
          <Field label="Invoices">{l.invoice_refs.length ? l.invoice_refs.join(', ') : '—'}</Field>
          <Field label="Source">{l.source === 'staff' ? 'Issued by staff' : `Order ${l.order_ref}`}</Field>
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
            <div className="text-sm font-semibold mb-3">Activity</div>
            <LicenceTimeline events={l.events} />
            <div className="text-xs text-neutral-500 mt-3">Manage shows the same timeline to the customer, without internal reasons and with “AICOUNTLY team” instead of staff names.</div>
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
