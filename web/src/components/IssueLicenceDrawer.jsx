import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../lib/api.js'
import Drawer from './Drawer.jsx'
import { ErrorNote, describeError } from './LicensingBits.jsx'
import { formatPaise, newIdempotencyKey, termText, todayIST } from '../lib/licensing.js'

const pickRows = (r) => r.data?.data?.items || r.data?.data || []

/**
 * Issue a licence from a published offer — to a HOLDER (the my.aicountly account owner; D-L2, the default) or, for
 * legacy company-level licences, to a mapped Manage company. Each opening of the drawer carries one Idempotency-Key,
 * so a double click or a retry after a network error never issues twice.
 */
export default function IssueLicenceDrawer({ open, onClose, onIssued, accountId = null, cmpId = null, holderUuid = null }) {
  const qc = useQueryClient()
  const blank = () => ({
    target: cmpId ? 'company' : 'holder', mapping: '', account_id: accountId ? String(accountId) : '', accountQ: '',
    holderQ: holderUuid || '', holder_uuid: holderUuid || '', holder_note: '', also_company: false,
    offer_id: '', starts_at: todayIST(), activate: true, seats_total: '', invoice_refs: '', notes: '', is_addon: false, quote_ref: '',
  })
  const [f, setF] = useState(blank)
  const [error, setError] = useState(null)
  const [idemKey, setIdemKey] = useState(newIdempotencyKey)
  useEffect(() => { if (open) { setF(blank()); setError(null); setIdemKey(newIdempotencyKey()) } }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const mappings = useQuery({
    queryKey: ['account-companies', accountId || 'all', 'active'],
    enabled: open,
    queryFn: () => api.get('/v1/account-companies', { params: { status: 'active', ...(accountId ? { account_id: accountId } : {}) } }).then((r) => r.data?.data?.items || []),
  })
  const offers = useQuery({ queryKey: ['offers', 'published'], enabled: open, queryFn: () => api.get('/v1/offers', { params: { status: 'published' } }).then((r) => r.data?.data?.items || []) })
  const holders = useQuery({
    queryKey: ['holders', f.holderQ],
    enabled: open && f.target === 'holder' && f.holderQ.trim().length >= 2,
    queryFn: () => api.get('/v1/holders', { params: { q: f.holderQ.trim() } }).then(pickRows),
  })
  const accounts = useQuery({
    queryKey: ['accounts-pick', f.accountQ],
    enabled: open && f.target === 'holder' && !accountId,
    queryFn: () => api.get('/v1/accounts', { params: { q: f.accountQ, limit: 20 } }).then(pickRows),
  })
  const chosenMapping = useMemo(() => {
    const list = mappings.data || []
    return list.find((m) => String(m.id) === String(f.mapping)) || (cmpId ? list.find((m) => m.cmp_id === Number(cmpId)) : null) || (list.length === 1 ? list[0] : null)
  }, [mappings.data, f.mapping, cmpId])
  const offer = (offers.data || []).find((o) => String(o.id) === String(f.offer_id))
  const knownHolder = (holders.data || []).find((h) => h.holder_uuid === f.holder_uuid.trim())

  const issue = useMutation({
    mutationFn: () => {
      if (!offer) throw new Error('Choose a published offer.')
      const body = {
        offer_code: offer.offer_code, offer_version: offer.version, starts_at: f.starts_at || null, activate: f.activate,
        seats_total: f.seats_total === '' ? undefined : Number(f.seats_total), invoice_refs: f.invoice_refs, notes: f.notes, is_addon: f.is_addon,
        ...(f.quote_ref.trim() ? { quote_ref: f.quote_ref.trim() } : {}),
      }
      if (f.target === 'holder') {
        if (!f.holder_uuid.trim()) throw new Error('Choose the holder (search by e-mail) or type their my.aicountly uuid.')
        if (!f.account_id) throw new Error('Choose the Engage account this sale belongs to.')
        Object.assign(body, { holder_uuid: f.holder_uuid.trim(), account_id: Number(f.account_id) })
        if (f.holder_note.trim()) body.holder_note = f.holder_note.trim()
        if (f.also_company && chosenMapping) body.cmp_id = chosenMapping.cmp_id
      } else {
        if (!chosenMapping) throw new Error('Choose the mapped Manage company.')
        Object.assign(body, { account_id: chosenMapping.account_id, cmp_id: chosenMapping.cmp_id })
      }
      return api.post('/v1/licences', body, { headers: { 'Idempotency-Key': idemKey } }).then((r) => r.data?.data)
    },
    onSuccess: (data) => { setError(null); qc.invalidateQueries({ queryKey: ['licences'] }); onIssued?.(data) },
    onError: (e) => setError(describeError(e)),
  })

  const set = (patch) => setF((x) => ({ ...x, ...patch }))
  return (
    <Drawer open={open} size="lg" title="Issue a licence" onClose={onClose}
      footer={<button className="engage-btn-primary" disabled={issue.isPending} onClick={() => { if (window.confirm('Issue this licence? Manage receives the new plan automatically within a minute.')) issue.mutate() }}>Issue licence</button>}>
      <ErrorNote error={error} />
      <div className="flex gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="radio" checked={f.target === 'holder'} onChange={() => set({ target: 'holder' })} /> Holder (account owner — every company they own)</label>
        <label className="flex items-center gap-2"><input type="radio" checked={f.target === 'company'} onChange={() => set({ target: 'company' })} /> One Manage company (legacy)</label>
      </div>
      {f.target === 'holder' ? <>
        <div>
          <div className="engage-label">Find the holder (e-mail, name or my.aicountly uuid)</div>
          <input className="engage-input" placeholder="asha@example.com" value={f.holderQ} onChange={(e) => set({ holderQ: e.target.value })} />
          {(holders.data || []).length ? (
            <div className="mt-1 border border-neutral-200 rounded-md divide-y max-h-40 overflow-auto">
              {holders.data.map((h) => (
                <button type="button" key={h.holder_uuid} className={'w-full text-left px-2 py-1 text-sm hover:bg-neutral-50 ' + (h.holder_uuid === f.holder_uuid ? 'bg-aicountly-50' : '')} onClick={() => set({ holder_uuid: h.holder_uuid })}>
                  <span className="font-mono text-xs">{h.holder_uuid}</span> · {h.email || '—'} {h.name ? `· ${h.name}` : ''} <span className="text-xs text-neutral-500">({h.licences_count} licence{h.licences_count === 1 ? '' : 's'})</span>
                </button>
              ))}
            </div>
          ) : f.holderQ.trim().length >= 2 && holders.isFetched ? <div className="text-xs text-neutral-500 mt-1">No holder found — Engage knows holders once they sign up (Basic provisioning). You can type the uuid below.</div> : null}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><div className="engage-label">Holder uuid</div><input className="engage-input font-mono" value={f.holder_uuid} onChange={(e) => set({ holder_uuid: e.target.value })} /></div>
          {accountId ? null : (
            <div>
              <div className="engage-label">Engage account</div>
              <input className="engage-input mb-1" placeholder="Search accounts…" value={f.accountQ} onChange={(e) => set({ accountQ: e.target.value })} />
              <select className="engage-input" value={f.account_id} onChange={(e) => set({ account_id: e.target.value })}>
                <option value="">Choose…</option>
                {(accounts.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </div>
          )}
        </div>
        {f.holder_uuid.trim() && !knownHolder ? (
          <div><div className="engage-label">How did you verify this uuid? (required for a holder Engage has not heard of)</div><input className="engage-input" placeholder="e.g. the owner read it out from my.aicountly on a call" value={f.holder_note} onChange={(e) => set({ holder_note: e.target.value })} /></div>
        ) : null}
        {(mappings.data || []).length ? (
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.also_company} onChange={(e) => set({ also_company: e.target.checked })} /> Also record a mapped Manage company{f.also_company && chosenMapping ? `: ${chosenMapping.cmp_id}` : ''}</label>
        ) : null}
      </> : null}
      {f.target === 'company' || f.also_company ? (
        <div>
          <div className="engage-label">Manage company</div>
          <select className="engage-input" value={chosenMapping ? chosenMapping.id : ''} onChange={(e) => set({ mapping: e.target.value })}>
            <option value="">Choose…</option>
            {(mappings.data || []).map((m) => <option key={m.id} value={m.id}>{m.cmp_id} · {m.company_name || '—'} · {m.account_name}</option>)}
          </select>
          {mappings.data && mappings.data.length === 0 ? <div className="text-xs text-amber-700 mt-1">No company is mapped{accountId ? ' to this account' : ''} yet — map one first.</div> : null}
        </div>
      ) : null}
      <div>
        <div className="engage-label">Offer (published versions only)</div>
        <select className="engage-input" value={f.offer_id} onChange={(e) => set({ offer_id: e.target.value, seats_total: '' })}>
          <option value="">Choose…</option>
          {(offers.data || []).map((o) => <option key={o.id} value={o.id}>{o.label}{o.is_default ? ' (Basic)' : ''} · {o.offer_code} v{o.version} · {formatPaise(o.price_paise)}</option>)}
        </select>
        {offer ? <div className="text-xs text-neutral-600 mt-1">{offer.product.label} · {offer.licence_type.label} · {termText(offer.term_months, offer.term_days)} · seats {offer.seats ?? 'no limit'} · apps {(offer.app_keys || []).join(', ')}</div> : null}
        {offer?.is_default ? <div className="text-xs text-amber-700 mt-1">This is the product's Basic plan — holders get it automatically at sign-up.</div> : null}
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><div className="engage-label">Starts on</div><input className="engage-input" type="date" value={f.starts_at} onChange={(e) => set({ starts_at: e.target.value })} /></div>
        <div><div className="engage-label">Seats (empty = offer default)</div><input className="engage-input" type="number" min={0} value={f.seats_total} onChange={(e) => set({ seats_total: e.target.value })} /></div>
      </div>
      {offer && f.starts_at && (offer.term_months || offer.term_days) ? (
        <div className="text-xs text-neutral-500 -mt-2">Ends after {termText(offer.term_months, offer.term_days)} (last day shown on the licence).</div>
      ) : null}
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.activate} onChange={(e) => set({ activate: e.target.checked })} /> Activate now (otherwise it stays “issued” until you activate it)</label>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.is_addon} onChange={(e) => set({ is_addon: e.target.checked })} /> Add-on: a second live licence of this product for the same holder / company, on purpose</label>
      <div><div className="engage-label">Invoice numbers (optional, comma separated)</div><input className="engage-input font-mono" placeholder="e.g. the invoice number" value={f.invoice_refs} onChange={(e) => set({ invoice_refs: e.target.value })} /></div>
      <div><div className="engage-label">Quote ref (optional — from Sales → Quotes; commits its coupon with this licence)</div><input className="engage-input font-mono uppercase" placeholder="QTE-000012" value={f.quote_ref} onChange={(e) => set({ quote_ref: e.target.value })} /></div>
      <div><div className="engage-label">Internal notes (not shown to the customer)</div><textarea className="engage-input" rows={2} value={f.notes} onChange={(e) => set({ notes: e.target.value })} /></div>
    </Drawer>
  )
}
