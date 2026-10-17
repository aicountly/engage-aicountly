import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { COMMISSION_ADMIN } from '../../lib/sellerRoles.js'

const EXAMPLES = {
  percent_of_first_payment: { type: 'percent_of_first_payment', percent: '' },
  percent_recurring_months: { type: 'percent_recurring_months', percent: '', months: 12 },
  flat_per_conversion: { type: 'flat_per_conversion', amount: '' },
  slab: { type: 'slab', applies_to: 'first_payment', slabs: [{ from: '0.00', to: '50000.00', percent: '' }, { from: '50000.00', to: null, percent: '' }] },
}

/**
 * Seller commission plans. None is shipped: nothing accrues until a plan is published here.
 * Rates are typed by the admin — Engage never suggests one.
 */
export default function CommissionPlans() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(COMMISSION_ADMIN)
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState(null)
  const q = useQuery({ queryKey: ['commission-plans'], queryFn: () => api.get('/v1/commission-plans').then((r) => r.data?.data) })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['commission-plans'] })
  const save = useMutation({
    mutationFn: (d) => {
      let rules
      try { rules = JSON.parse(d.rulesText) } catch { throw new Error('Rules must be valid JSON.') }
      const body = { name: d.name, description: d.description, rules, hold_days: Number(d.hold_days), product_scope: d.scope.split(',').map((s) => s.trim()).filter(Boolean) }
      return (d.id ? api.put(`/v1/commission-plans/${d.id}`, body) : api.post('/v1/commission-plans', { ...body, plan_code: d.plan_code })).then((r) => r.data?.data)
    },
    onSuccess: () => { setDraft(null); setError(null); invalidate() },
    onError: (e) => setError(apiError(e)),
  })
  const act = useMutation({
    mutationFn: ({ id, action }) => api.post(`/v1/commission-plans/${id}/${action}`).then((r) => r.data?.data),
    onSuccess: (data, vars) => { setError(null); invalidate(); if (vars.action === 'new-version') edit(data) },
    onError: (e) => setError(apiError(e)),
  })
  const edit = (p) => setDraft({ id: p.id, plan_code: p.plan_code, name: p.name, description: p.description || '', hold_days: p.hold_days, scope: (p.product_scope || ['*']).join(', '), rulesText: JSON.stringify(p.rules, null, 2) })
  const items = q.data?.items || []

  return (
    <>
      <PageHeader
        title="Commission plans"
        subtitle="Versioned plans: draft → active → retired. Publishing a new version retires the previous one. Entries record the exact version."
        actions={canEdit ? <button className="engage-btn-primary" onClick={() => setDraft({ plan_code: '', name: '', description: '', hold_days: 15, scope: '*', rulesText: JSON.stringify(EXAMPLES.percent_of_first_payment, null, 2) })}>+ New plan</button> : null}
      />
      {error ? <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      {q.data && q.data.active_count === 0 ? (
        <div className="mb-4 rounded-md bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">
          No commission plan is published, so no commission accrues. Sellers see “No commission plan published yet”.
        </div>
      ) : null}
      <div className="space-y-3">
        {items.map((p) => (
          <div key={p.id} className="engage-card">
            <div className="flex items-center justify-between gap-2">
              <div><span className="font-semibold">{p.name}</span> <span className="font-mono text-xs">{p.plan_code} v{p.version}</span> <StatusBadge status={p.status} /></div>
              <div className="flex gap-2">
                {canEdit && p.status === 'draft' ? <>
                  <button className="engage-btn-secondary text-xs" onClick={() => edit(p)}>Edit</button>
                  <button className="engage-btn-primary text-xs" onClick={() => { if (window.confirm(`Publish ${p.name} v${p.version}? Commission starts accruing under it.`)) act.mutate({ id: p.id, action: 'publish' }) }}>Publish</button>
                </> : null}
                {canEdit && p.status !== 'draft' ? <button className="engage-btn-secondary text-xs" onClick={() => act.mutate({ id: p.id, action: 'new-version' })}>New version</button> : null}
                {canEdit && p.status !== 'retired' ? <button className="engage-btn-danger text-xs" onClick={() => { if (window.confirm('Retire this plan? Existing entries keep it; nothing new accrues under it.')) act.mutate({ id: p.id, action: 'retire' }) }}>Retire</button> : null}
              </div>
            </div>
            <div className="mt-2 grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
              <div><span className="text-neutral-400">Products:</span> {(p.product_scope || []).join(', ')}</div>
              <div><span className="text-neutral-400">Hold:</span> {p.hold_days} days</div>
              <div><span className="text-neutral-400">Published:</span> {formatDate(p.published_at)}</div>
            </div>
            <pre className="mt-2 text-xs bg-neutral-50 rounded p-2 overflow-x-auto">{JSON.stringify(p.rules, null, 2)}</pre>
          </div>
        ))}
      </div>
      <Drawer open={!!draft} title={draft?.id ? 'Edit draft plan' : 'New commission plan (draft)'} onClose={() => setDraft(null)}
        footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate(draft)}>Save draft</button>}>
        {draft ? <>
          {!draft.id ? <div><div className="engage-label">Plan code</div><input className="engage-input" value={draft.plan_code} placeholder="standard" onChange={(e) => setDraft({ ...draft, plan_code: e.target.value })} /></div> : null}
          <div><div className="engage-label">Name</div><input className="engage-input" value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></div>
          <div><div className="engage-label">Description</div><input className="engage-input" value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} /></div>
          <div><div className="engage-label">Products (codes, comma separated, or *)</div><input className="engage-input" value={draft.scope} onChange={(e) => setDraft({ ...draft, scope: e.target.value })} /></div>
          <div><div className="engage-label">Hold days (refund / clawback window)</div><input className="engage-input" type="number" min={0} max={365} value={draft.hold_days} onChange={(e) => setDraft({ ...draft, hold_days: e.target.value })} /></div>
          <div>
            <div className="engage-label">Rule (JSON) — start from:</div>
            <div className="flex flex-wrap gap-1 mb-1">
              {Object.keys(EXAMPLES).map((k) => <button key={k} className="engage-btn-secondary text-xs" onClick={() => setDraft({ ...draft, rulesText: JSON.stringify(EXAMPLES[k], null, 2) })}>{k}</button>)}
            </div>
            <textarea className="engage-input font-mono text-xs" rows={12} value={draft.rulesText} onChange={(e) => setDraft({ ...draft, rulesText: e.target.value })} />
            <div className="text-xs text-neutral-500 mt-1">Money as "1234.50", percent as "7.5". Fill in the rates — nothing is pre-filled.</div>
          </div>
        </> : null}
      </Drawer>
    </>
  )
}
