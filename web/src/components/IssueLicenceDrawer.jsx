import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../lib/api.js'
import Drawer from './Drawer.jsx'
import { ErrorNote, describeError } from './LicensingBits.jsx'
import { formatPaise, termText, todayIST } from '../lib/licensing.js'

/** Issue a licence from a published offer to a mapped Manage company. */
export default function IssueLicenceDrawer({ open, onClose, onIssued, accountId = null, cmpId = null }) {
  const qc = useQueryClient()
  const [f, setF] = useState({ mapping: '', offer_id: '', starts_at: todayIST(), activate: true, seats_total: '', invoice_refs: '', notes: '' })
  const [error, setError] = useState(null)
  const mappings = useQuery({
    queryKey: ['account-companies', accountId || 'all', 'active'],
    enabled: open,
    queryFn: () => api.get('/v1/account-companies', { params: { status: 'active', ...(accountId ? { account_id: accountId } : {}) } }).then((r) => r.data?.data?.items || []),
  })
  const offers = useQuery({ queryKey: ['offers', 'published'], enabled: open, queryFn: () => api.get('/v1/offers', { params: { status: 'published' } }).then((r) => r.data?.data?.items || []) })
  const chosenMapping = useMemo(() => {
    const list = mappings.data || []
    return list.find((m) => String(m.id) === String(f.mapping)) || (cmpId ? list.find((m) => m.cmp_id === Number(cmpId)) : null) || (list.length === 1 ? list[0] : null)
  }, [mappings.data, f.mapping, cmpId])
  const offer = (offers.data || []).find((o) => String(o.id) === String(f.offer_id))
  const issue = useMutation({
    mutationFn: () => {
      if (!chosenMapping) throw new Error('Choose the mapped Manage company.')
      if (!offer) throw new Error('Choose a published offer.')
      return api.post('/v1/licences', {
        account_id: chosenMapping.account_id, cmp_id: chosenMapping.cmp_id, offer_code: offer.offer_code, offer_version: offer.version,
        starts_at: f.starts_at || null, activate: f.activate, seats_total: f.seats_total === '' ? undefined : Number(f.seats_total),
        invoice_refs: f.invoice_refs, notes: f.notes,
      }).then((r) => r.data?.data)
    },
    onSuccess: (data) => { setError(null); qc.invalidateQueries({ queryKey: ['licences'] }); onIssued?.(data) },
    onError: (e) => setError(describeError(e)),
  })

  return (
    <Drawer open={open} size="lg" title="Issue a licence" onClose={onClose}
      footer={<button className="engage-btn-primary" disabled={issue.isPending} onClick={() => { if (window.confirm('Issue this licence? It appears to the customer in Manage once Manage is connected.')) issue.mutate() }}>Issue licence</button>}>
      <ErrorNote error={error} />
      <div>
        <div className="engage-label">Manage company</div>
        <select className="engage-input" value={chosenMapping ? chosenMapping.id : ''} onChange={(e) => setF({ ...f, mapping: e.target.value })}>
          <option value="">Choose…</option>
          {(mappings.data || []).map((m) => <option key={m.id} value={m.id}>{m.cmp_id} · {m.company_name || '—'} · {m.account_name}</option>)}
        </select>
        {mappings.data && mappings.data.length === 0 ? <div className="text-xs text-amber-700 mt-1">No company is mapped{accountId ? ' to this account' : ''} yet — map one first.</div> : null}
      </div>
      <div>
        <div className="engage-label">Offer (published versions only)</div>
        <select className="engage-input" value={f.offer_id} onChange={(e) => setF({ ...f, offer_id: e.target.value, seats_total: '' })}>
          <option value="">Choose…</option>
          {(offers.data || []).map((o) => <option key={o.id} value={o.id}>{o.label} · {o.offer_code} v{o.version} · {formatPaise(o.price_paise)}</option>)}
        </select>
        {offer ? <div className="text-xs text-neutral-600 mt-1">{offer.product.label} · {offer.licence_type.label} · {termText(offer.term_months, offer.term_days)} · seats {offer.seats ?? 'no limit'}</div> : null}
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><div className="engage-label">Starts on</div><input className="engage-input" type="date" value={f.starts_at} onChange={(e) => setF({ ...f, starts_at: e.target.value })} /></div>
        <div><div className="engage-label">Seats (empty = offer default)</div><input className="engage-input" type="number" min={0} value={f.seats_total} onChange={(e) => setF({ ...f, seats_total: e.target.value })} /></div>
      </div>
      {offer && f.starts_at && (offer.term_months || offer.term_days) ? (
        <div className="text-xs text-neutral-500 -mt-2">Ends after {termText(offer.term_months, offer.term_days)} (last day shown on the licence).</div>
      ) : null}
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.activate} onChange={(e) => setF({ ...f, activate: e.target.checked })} /> Activate now (otherwise it stays “issued” until you activate it)</label>
      <div><div className="engage-label">Invoice numbers (optional, comma separated)</div><input className="engage-input font-mono" placeholder="e.g. the Smart Books invoice number" value={f.invoice_refs} onChange={(e) => setF({ ...f, invoice_refs: e.target.value })} /></div>
      <div><div className="engage-label">Internal notes (not shown to the customer)</div><textarea className="engage-input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></div>
    </Drawer>
  )
}
