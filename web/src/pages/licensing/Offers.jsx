import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { pickRows } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import EntitlementCodePicker, { appKeysOf } from '../../components/EntitlementCodePicker.jsx'
import { ErrorNote, OfferStatusBadge, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN, formatPaise, paiseToRupees, parseJsonField, rupeesToPaise, termText } from '../../lib/licensing.js'

const EMPTY = {
  offer_code: '', product_code: '', licence_type_code: '', plan_code: '', label: '', description: '',
  price: '', price_basis: '', gst_rate: '', sac: '', term_months: 0, term_days: 0, seats: '', app_keys: '',
  entitlementsText: '[]', displayText: '{}', valid_from: '', valid_to: '', is_default: false,
}

const dateOnly = (iso) => (iso ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date(iso)) : '')

/**
 * The sellable catalogue. Every price, rate, SAC and term is typed by staff — nothing is pre-filled.
 * Versions: draft → published (immutable) → retired. Publishing a version retires the previous one.
 */
export default function Offers() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState(null)
  const [status, setStatus] = useState('')
  const offers = useQuery({ queryKey: ['offers', status], queryFn: () => api.get('/v1/offers', { params: status ? { status } : {} }).then((r) => r.data?.data?.items || []) })
  const products = useQuery({ queryKey: ['products'], queryFn: () => api.get('/v1/products').then(pickRows) })
  const plans = useQuery({ queryKey: ['plans'], queryFn: () => api.get('/v1/plans').then(pickRows) })
  const types = useQuery({ queryKey: ['licence-types'], queryFn: () => api.get('/v1/licence-types').then((r) => r.data?.data?.items || []) })
  // Which products still have no published Basic. Every signed-up person is meant to hold the
  // free Basic of every app (D-L1), but provisioning can only issue a Basic that exists: a
  // product without one is skipped silently and every sign-up since is short a licence.
  const readiness = useQuery({ queryKey: ['offers', 'basic-readiness'], queryFn: () => api.get('/v1/offers/basic-readiness').then((r) => r.data?.data) })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['offers'] })

  const grouped = useMemo(() => {
    const m = new Map()
    for (const o of offers.data || []) {
      if (!m.has(o.offer_code)) m.set(o.offer_code, [])
      m.get(o.offer_code).push(o)
    }
    return [...m.entries()]
  }, [offers.data])

  const save = useMutation({
    mutationFn: (d) => {
      const price = rupeesToPaise(d.price)
      if (price === null) throw new Error('Enter the price in rupees, e.g. 11800 or 11800.50.')
      const body = {
        product_code: d.product_code, licence_type_code: d.licence_type_code, plan_code: d.plan_code || null,
        label: d.label, description: d.description, price_paise: price, price_basis: d.price_basis, gst_rate: d.gst_rate,
        sac: d.sac, term_months: Number(d.term_months || 0), term_days: Number(d.term_days || 0),
        seats: d.seats === '' ? null : Number(d.seats), app_keys: d.app_keys,
        entitlements: parseJsonField(d.entitlementsText, 'Entitlements', { expect: 'array' }),
        display: parseJsonField(d.displayText, 'Display'),
        valid_from: d.valid_from || null, valid_to: d.valid_to || null, is_default: !!d.is_default,
      }
      return (d.id ? api.put(`/v1/offers/${d.id}`, body) : api.post('/v1/offers', { ...body, offer_code: d.offer_code })).then((r) => r.data?.data)
    },
    onSuccess: () => { setDraft(null); setError(null); invalidate() },
    onError: (e) => setError(describeError(e)),
  })
  const act = useMutation({
    mutationFn: ({ id, action, body }) => (action === 'discard' ? api.delete(`/v1/offers/${id}`) : api.post(`/v1/offers/${id}/${action}`, body)).then((r) => r.data?.data),
    onSuccess: (data, vars) => { setError(null); invalidate(); if (vars.action === 'new-version') edit(data) },
    onError: (e) => setError(describeError(e)),
  })
  const edit = (o) => setDraft({
    id: o.id, offer_code: o.offer_code, version: o.version, product_code: o.product.code, licence_type_code: o.licence_type.code,
    plan_code: o.plan?.code || '', label: o.label, description: o.description || '', price: paiseToRupees(o.price_paise),
    price_basis: o.price_basis, gst_rate: o.gst_rate, sac: o.sac || '', term_months: o.term_months, term_days: o.term_days,
    seats: o.seats ?? '', app_keys: (o.app_keys || []).join(', '), entitlementsText: JSON.stringify(o.entitlements || [], null, 2),
    displayText: JSON.stringify(o.display || {}, null, 2), valid_from: dateOnly(o.valid_from), valid_to: dateOnly(o.valid_to),
    is_default: !!o.is_default,
  })
  const set = (k) => (e) => setDraft({ ...draft, [k]: e.target.value })

  return (
    <>
      <PageHeader
        title="Offers"
        subtitle="Versioned catalogue Manage shows to customers. Published versions are immutable; change an offer by publishing a new version."
        actions={<>
          <select className="engage-input w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option><option value="draft">Draft</option><option value="published">Published</option><option value="retired">Retired</option>
          </select>
          {canEdit ? <button className="engage-btn-primary" onClick={() => { setError(null); setDraft({ ...EMPTY }) }}>+ New offer</button> : null}
        </>}
      />
      <ErrorNote error={!draft ? error : null} />
      <BasicReadinessNote data={readiness.data} />
      {!offers.isLoading && grouped.length === 0 ? (
        <div className="engage-card text-sm text-neutral-600">
          No offers yet. {canEdit ? 'Create a licence type first, then an offer on it. Nothing is sold until an offer is published.' : 'An administrator or sales manager publishes offers.'}
        </div>
      ) : null}
      <div className="space-y-3">
        {grouped.map(([code, versions]) => (
          <div key={code} className="engage-card">
            <div className="font-mono text-xs text-neutral-500 mb-2">{code}</div>
            <div className="space-y-2">
              {versions.map((o) => (
                <div key={o.id} className="border border-neutral-100 rounded-md p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold">{o.label}</span>
                      <span className="font-mono text-xs">v{o.version}</span>
                      <OfferStatusBadge status={o.status} />
                      {o.is_default ? <span className="engage-pill bg-aicountly-50 text-aicountly-800 border-aicountly-200">Basic (default)</span> : null}
                    </div>
                    {canEdit ? (
                      <div className="flex gap-2">
                        {o.status === 'draft' ? <>
                          <button className="engage-btn-secondary text-xs" onClick={() => { setError(null); edit(o) }}>Edit</button>
                          <button className="engage-btn-secondary text-xs" onClick={() => { if (window.confirm(`Discard draft ${code} v${o.version}?`)) act.mutate({ id: o.id, action: 'discard' }) }}>Discard</button>
                          <button className="engage-btn-primary text-xs" onClick={() => { if (window.confirm(`Publish ${code} v${o.version}? It becomes immutable and goes on sale${versions.some((x) => x.status === 'published') ? '; the current published version is retired' : ''}.`)) act.mutate({ id: o.id, action: 'publish' }) }}>Publish</button>
                        </> : null}
                        {o.status !== 'draft' && !versions.some((x) => x.status === 'draft') ? <button className="engage-btn-secondary text-xs" onClick={() => act.mutate({ id: o.id, action: 'new-version' })}>New version</button> : null}
                        {o.status !== 'retired' ? (
                          <button className="engage-btn-secondary text-xs" onClick={() => {
                            const msg = o.is_default
                              ? `Stop ${code} being the Basic (default) plan of ${o.product.label}? New sign-ups will not get it; existing Basic licences stay.`
                              : `Make ${code} the Basic (default) plan of ${o.product.label}? Every signed-up user gets it automatically once published. It must be free, open-ended and of a complimentary licence type.`
                            if (window.confirm(msg)) act.mutate({ id: o.id, action: 'default', body: { is_default: !o.is_default } })
                          }}>{o.is_default ? 'Unset Basic' : 'Make Basic'}</button>
                        ) : null}
                        {o.status === 'published' ? <button className="engage-btn-danger text-xs" onClick={() => { if (window.confirm('Retire this version? Existing licences keep it; it can no longer be issued.')) act.mutate({ id: o.id, action: 'retire' }) }}>Retire</button> : null}
                      </div>
                    ) : null}
                  </div>
                  <div className="mt-2 grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                    <div><span className="text-neutral-400">Product:</span> {o.product.label}</div>
                    <div><span className="text-neutral-400">Type:</span> {o.licence_type.label}</div>
                    <div><span className="text-neutral-400">Price:</span> {formatPaise(o.price_paise)} ({o.price_basis === 'inclusive' ? 'incl.' : 'excl.'} GST {o.gst_rate}%)</div>
                    <div><span className="text-neutral-400">SAC:</span> {o.sac || '—'}</div>
                    <div><span className="text-neutral-400">Term:</span> {termText(o.term_months, o.term_days)}</div>
                    <div><span className="text-neutral-400">Seats:</span> {o.seats ?? 'No limit'}</div>
                    <div><span className="text-neutral-400">Apps:</span> {(o.app_keys || []).join(', ')}</div>
                    <div><span className="text-neutral-400">On sale:</span> {o.valid_from ? formatDateIN(o.valid_from) : 'now'} → {o.valid_to ? formatDateIN(o.valid_to) : 'open'}</div>
                  </div>
                  {o.entitlements?.length ? <div className="mt-1 text-xs text-neutral-600">{o.entitlements.map((e) => `${e.label}${e.value !== null && e.value !== undefined ? `: ${e.value}${e.unit ? ' ' + e.unit : ''}` : ''}`).join(' · ')}</div> : null}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
      <Drawer open={!!draft} size="xl" title={draft?.id ? `Edit draft ${draft.offer_code} v${draft.version}` : 'New offer (draft v1)'} onClose={() => setDraft(null)}
        footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate(draft)}>Save draft</button>}>
        {draft ? <>
          <ErrorNote error={error} />
          {!draft.id ? <div><div className="engage-label">Offer code (permanent)</div><input className="engage-input font-mono" value={draft.offer_code} onChange={set('offer_code')} /></div> : null}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div><div className="engage-label">Product</div>
              <select className="engage-input" value={draft.product_code} onChange={set('product_code')}>
                <option value="">Choose…</option>{(products.data || []).map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
              </select></div>
            <div><div className="engage-label">Licence type</div>
              <select className="engage-input" value={draft.licence_type_code} onChange={set('licence_type_code')}>
                <option value="">Choose…</option>{(types.data || []).filter((t) => t.active).map((t) => <option key={t.code} value={t.code}>{t.label}</option>)}
              </select></div>
            <div><div className="engage-label">Plan (optional)</div>
              <select className="engage-input" value={draft.plan_code} onChange={set('plan_code')}>
                <option value="">None</option>{(plans.data || []).map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
              </select></div>
          </div>
          <div><div className="engage-label">Label (shown to customers)</div><input className="engage-input" value={draft.label} onChange={set('label')} /></div>
          <div><div className="engage-label">Description</div><input className="engage-input" value={draft.description} onChange={set('description')} /></div>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div><div className="engage-label">Price (₹)</div><input className="engage-input" inputMode="decimal" value={draft.price} onChange={set('price')} /></div>
            <div><div className="engage-label">Price is</div>
              <select className="engage-input" value={draft.price_basis} onChange={set('price_basis')}>
                <option value="">Choose…</option><option value="inclusive">Inclusive of GST</option><option value="exclusive">Exclusive of GST</option>
              </select></div>
            <div><div className="engage-label">GST rate (%)</div><input className="engage-input" inputMode="decimal" value={draft.gst_rate} onChange={set('gst_rate')} /></div>
            <div><div className="engage-label">SAC</div><input className="engage-input" inputMode="numeric" value={draft.sac} onChange={set('sac')} /></div>
          </div>
          {rupeesToPaise(draft.price) !== null ? <div className="text-xs text-neutral-500 -mt-2">= {formatPaise(rupeesToPaise(draft.price))}</div> : null}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div><div className="engage-label">Term months</div><input className="engage-input" type="number" min={0} value={draft.term_months} onChange={set('term_months')} /></div>
            <div><div className="engage-label">Term days</div><input className="engage-input" type="number" min={0} value={draft.term_days} onChange={set('term_days')} /></div>
            <div><div className="engage-label">Seats (empty = no limit)</div><input className="engage-input" type="number" min={1} value={draft.seats} onChange={set('seats')} /></div>
            <div><div className="engage-label">App keys (comma separated)</div><input className="engage-input font-mono" placeholder="defaults to the product" value={draft.app_keys} onChange={set('app_keys')} /></div>
          </div>
          <div className="text-xs text-neutral-500 -mt-2">Term 0 months + 0 days = no end date. App keys are Manage's keys (books, inventory, billing, notes, docs …); publishing refuses a key Manage does not know. A priced offer needs a SAC of the form 99xxxx.</div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!draft.is_default} onChange={(e) => setDraft({ ...draft, is_default: e.target.checked })} /> Basic (default) plan of this product — free, no end date, complimentary type; every signed-up user gets it (one published per product)</label>
          <div className="grid grid-cols-2 gap-3">
            <div><div className="engage-label">On sale from (optional)</div><input className="engage-input" type="date" value={draft.valid_from} onChange={set('valid_from')} /></div>
            <div><div className="engage-label">On sale until (optional)</div><input className="engage-input" type="date" value={draft.valid_to} onChange={set('valid_to')} /></div>
          </div>
          <div>
            <div className="engage-label">Entitlements (JSON list of {'{code, label, value, unit}'}; absent code = unlimited)</div>
            <EntitlementCodePicker
              text={draft.entitlementsText}
              apps={appKeysOf(draft.app_keys, draft.product_code)}
              onChange={(t) => setDraft((d) => ({ ...d, entitlementsText: t }))}
            />
            <textarea className="engage-input font-mono text-xs" rows={5} value={draft.entitlementsText} onChange={set('entitlementsText')} />
          </div>
          <div>
            <div className="engage-label">Display (JSON object; optional attributes [{'{label, value}'}], cycle_label, anything Manage should render)</div>
            <textarea className="engage-input font-mono text-xs" rows={5} value={draft.displayText} onChange={set('displayText')} />
          </div>
        </> : null}
      </Drawer>
    </>
  )
}

/**
 * Products with no published Basic. Not an error — nothing is broken — but every day it stands,
 * every new sign-up is short a licence for those products and nothing else says so.
 *
 * Drafts are called out separately because they need a different action: someone has already run
 * `engage:basic-offers` and left the entitlements empty, so the work is to fill them in and
 * publish, not to start.
 */
function BasicReadinessNote({ data }) {
  if (!data || data.ready) return null
  const { missing, counts } = data
  const show = missing.slice(0, 12)
  return (
    <div className="engage-card border-amber-300 bg-amber-50 text-sm text-amber-900">
      <p className="font-medium">
        {counts.missing} product{counts.missing === 1 ? '' : 's'} ha{counts.missing === 1 ? 's' : 've'} no published Basic offer.
      </p>
      <p className="mt-1">
        Provisioning can only issue a Basic that exists, so every person who signs up holds no licence
        at all for {counts.missing === 1 ? 'this product' : 'these products'} — and the call still
        succeeds, so nothing else reports it.
        {counts.draft > 0 ? ` ${counts.draft} already ha${counts.draft === 1 ? 's' : 've'} a draft waiting: fill in the entitlements and publish.` : ''}
      </p>
      <p className="mt-2 flex flex-wrap gap-1">
        {show.map((p) => (
          <span key={p.code} className="rounded bg-amber-100 px-1.5 py-0.5 text-xs" title={p.draft ? 'Draft default offer waiting to be published' : 'No default offer at all'}>
            {p.name}{p.draft ? ' (draft)' : ''}
          </span>
        ))}
        {missing.length > show.length ? <span className="text-xs">and {missing.length - show.length} more</span> : null}
      </p>
      <p className="mt-2 text-xs">
        <code>php spark engage:basic-offers --apply</code> drafts the missing ones; publish each, then
        <code> php spark engage:provision-basic --pending</code> tops up the holders who missed out.
      </p>
    </div>
  )
}
