import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { TONE_CLASSES } from '../../lib/licensing.js'
import { RATE_TONES, TOKEN_ADMIN, multiplierText, rupees, sourceText } from '../../lib/tokens.js'
import TokenRateForm from './TokenRateForm.jsx'
import TokenPricingForm from './TokenPricingForm.jsx'

function RateStatusBadge({ status }) {
  return <span className={clsx('engage-pill', TONE_CLASSES[RATE_TONES[status]] || TONE_CLASSES.neutral)}>{status ? status.charAt(0).toUpperCase() + status.slice(1) : ''}</span>
}

/**
 * Credits → Token conversions.
 *
 * What a model's tokens cost a customer. Console owns which models exist and what tier each
 * carries; this screen owns the multiplier. Every model Console knows is listed, including the
 * ones no module is bound to — those read as "not in use" rather than being hidden, so a rate can
 * be set before a model goes live and nothing is a surprise later.
 *
 * A model in use with no rate of its own is a warning, not a silent default: it is charged at its
 * tier's fallback and said so, here and in Console.
 */
export default function TokenConversions() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(TOKEN_ADMIN)
  const [creating, setCreating] = useState(null)
  const [pricing, setPricing] = useState(false)
  const [showAll, setShowAll] = useState(false)
  const [error, setError] = useState(null)

  const cat = useQuery({ queryKey: ['token-rates', 'catalogue'], queryFn: () => api.get('/v1/token-rates/catalogue').then((r) => r.data?.data) })
  const rates = useQuery({ queryKey: ['token-rates', 'all'], queryFn: () => api.get('/v1/token-rates').then((r) => r.data?.data?.items || []) })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['token-rates'] })

  const act = useMutation({
    mutationFn: ({ id, action, body }) => api.post(`/v1/token-rates/${id}/${action}`, body || {}).then((r) => r.data?.data),
    onSuccess: () => { setError(null); invalidate() },
    onError: (e) => setError(e?.response?.data?.error || e.message),
  })

  const models = useMemo(() => {
    const rows = cat.data?.models || []
    return showAll ? rows : rows.filter((m) => m.in_use)
  }, [cat.data, showAll])

  const price = cat.data?.pricing
  const warnings = cat.data?.warnings || []

  return (
    <>
      <PageHeader
        title="Token conversions"
        subtitle="What a model's tokens cost a customer. Billable tokens = (input + output) × multiplier."
        actions={canEdit ? <>
          <button className="engage-btn-secondary" onClick={() => setPricing(true)}>Pricing & fallbacks</button>
          <button className="engage-btn-primary" onClick={() => setCreating({})}>+ New rate</button>
        </> : null}
      />

      {error ? <div className="engage-card border-red-300 bg-red-50 text-sm text-red-800 mb-3">{error}</div> : null}

      {cat.data && !cat.data.catalogue_available ? (
        <div className="engage-card border-amber-300 bg-amber-50 text-sm text-amber-900 mb-3">
          <p className="font-medium">The model list could not be read from Console.</p>
          <p className="mt-1">{cat.data.catalogue_error}</p>
          <p className="mt-1 text-xs">The rates below are Engage&rsquo;s own and are unaffected — they can still be set, activated and retired.</p>
        </div>
      ) : null}

      {warnings.length > 0 ? (
        <div className="engage-card border-amber-300 bg-amber-50 text-sm text-amber-900 mb-3">
          <p className="font-medium">{warnings.length} model{warnings.length === 1 ? ' is' : 's are'} in use with no rate set.</p>
          <ul className="mt-1 list-disc pl-5 space-y-0.5">{warnings.slice(0, 8).map((w) => <li key={w}>{w}</li>)}</ul>
          {warnings.length > 8 ? <p className="mt-1 text-xs">and {warnings.length - 8} more.</p> : null}
          <p className="mt-2 text-xs">They are charged at their tier&rsquo;s fallback rate — never free, and never silently.</p>
        </div>
      ) : null}

      {price ? (
        <div className="engage-card mb-4 text-sm">
          <div className="flex flex-wrap gap-x-8 gap-y-2">
            <div><div className="text-xs text-neutral-500">Price</div><div className="font-medium">{rupees(price.paise_per_1000_tokens)} per 1,000 tokens</div></div>
            <div><div className="text-xs text-neutral-500">GST</div><div className="font-medium">{price.gst_rate}% ({price.price_basis === 'exclusive' ? 'added on top' : 'included'})</div></div>
            <div><div className="text-xs text-neutral-500">SAC</div><div className="font-medium font-mono">{price.sac}</div></div>
            <div><div className="text-xs text-neutral-500">Fallback by tier</div><div className="font-medium">
              {['low', 'medium', 'high'].map((t) => `${t} ${multiplierText(cat.data.tier_fallback?.[t])}`).join(' · ')}
            </div></div>
            <div><div className="text-xs text-neutral-500">Unclassified model</div><div className="font-medium">{cat.data.unclassified_tier} tier</div></div>
          </div>
          <p className="mt-2 text-xs text-neutral-500">
            Rs 1,000 of credit buys {(100000 / price.paise_per_1000_tokens * 1000).toLocaleString('en-IN')} tokens, plus {price.gst_rate}% GST at checkout.
          </p>
        </div>
      ) : null}

      <div className="flex items-center justify-between mb-2">
        <h2 className="text-sm font-semibold text-neutral-700">Models</h2>
        <label className="text-xs text-neutral-600 flex items-center gap-1.5">
          <input type="checkbox" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />
          Show models no module is bound to
        </label>
      </div>
      <DataTable
        loading={cat.isLoading}
        rows={models}
        emptyMessage={cat.data?.catalogue_available ? 'No models are bound to a Console module yet.' : 'The model list is unavailable.'}
        columns={[
          { key: 'model', header: 'Model', render: (m) => <div><div className="font-medium">{m.display_name}</div><div className="text-xs text-neutral-500 font-mono">{m.provider_slug}/{m.model_key}</div></div> },
          { key: 'provider_name', header: 'Provider' },
          { key: 'tier', header: 'Tier', render: (m) => m.tier
            ? <span className="text-xs">{m.tier}</span>
            : <span className="engage-pill bg-amber-100 text-amber-800" title="Console has not classified this model">not set</span> },
          { key: 'in_use', header: 'In use', render: (m) => m.in_use
            ? <span className="text-xs">{m.module_count} module{m.module_count === 1 ? '' : 's'}</span>
            : <span className="engage-pill bg-neutral-100 text-neutral-600">inactive</span> },
          { key: 'multiplier', header: 'Counts as', align: 'right', render: (m) => <span className="font-medium">{m.multiplier}</span> },
          { key: 'source', header: 'Why', render: (m) => <span className={clsx('text-xs', m.warning ? 'text-amber-700' : 'text-neutral-500')}>{sourceText(m.source)}</span> },
          ...(canEdit ? [{ key: 'set', header: '', align: 'right', render: (m) => (
            <button className="engage-btn-secondary text-xs" onClick={() => setCreating({ provider_code: m.provider_slug, model_key: m.model_key, label: `${m.display_name}` })}>
              {m.source === 'model' ? 'Replace rate' : 'Set rate'}
            </button>
          ) }] : []),
        ]}
      />

      <h2 className="text-sm font-semibold text-neutral-700 mt-6 mb-2">Rates</h2>
      <DataTable
        loading={rates.isLoading}
        rows={rates.data || []}
        emptyMessage="No rates yet. Every model falls back to its Console tier until one is set and activated."
        columns={[
          { key: 'rate_ref', header: 'Ref', render: (r) => <span className="font-mono text-xs">{r.rate_ref}</span> },
          { key: 'label', header: 'Rate', render: (r) => <div><div className="font-medium">{r.label}</div><div className="text-xs text-neutral-500 font-mono">{r.provider_code}/{r.model_key || '* every model'}</div></div> },
          { key: 'multiplier', header: 'Multiplier', align: 'right', render: (r) => <span className="font-medium">{r.multiplier}</span> },
          { key: 'status', header: 'Status', render: (r) => <RateStatusBadge status={r.status} /> },
          ...(canEdit ? [{ key: 'actions', header: '', align: 'right', render: (r) => (
            <div className="flex justify-end gap-1.5">
              {r.status === 'draft' ? (
                <button className="engage-btn-secondary text-xs" disabled={act.isPending} onClick={() => act.mutate({ id: r.id, action: 'activate' })}>Activate</button>
              ) : null}
              {r.status !== 'retired' ? (
                <button className="engage-btn-secondary text-xs" disabled={act.isPending} onClick={() => {
                  const reason = window.prompt('Why is this rate being retired?')
                  if (reason && reason.trim().length >= 3) act.mutate({ id: r.id, action: 'retire', body: { reason } })
                }}>Retire</button>
              ) : null}
            </div>
          ) }] : []),
        ]}
      />
      <p className="mt-2 text-xs text-neutral-500">
        A rate is approved by someone other than whoever wrote or edited it, and once active it can never be
        edited — retire it and add a new one, so what priced a past call stays on record.
      </p>

      {creating ? <TokenRateForm open initial={creating} onClose={() => setCreating(null)} onSaved={() => { setCreating(null); invalidate() }} /> : null}
      {pricing ? <TokenPricingForm open data={cat.data} onClose={() => setPricing(false)} onSaved={() => { setPricing(false); invalidate() }} /> : null}
    </>
  )
}
