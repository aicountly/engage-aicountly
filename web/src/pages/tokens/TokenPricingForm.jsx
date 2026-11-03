import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import api from '../../lib/api.js'
import Drawer from '../../components/Drawer.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { TIER_LABELS, multiplierText, bpFromMultiplier, rupees } from '../../lib/tokens.js'

/**
 * What tokens cost, and what an unrated model falls back to.
 *
 * Only the fields that are changed are sent, so a screen opened to fix the price cannot silently
 * reset the tier table.
 */
export default function TokenPricingForm({ open, data, onClose, onSaved }) {
  const p = data?.pricing || {}
  const tiers = data?.tier_fallback || {}
  const [f, setF] = useState({
    paise_per_1000_tokens: p.paise_per_1000_tokens ?? 100,
    gst_rate: p.gst_rate ?? 18,
    sac: p.sac ?? '997331',
    low: (tiers.low ?? 10000) / 10000,
    medium: (tiers.medium ?? 100000) / 10000,
    high: (tiers.high ?? 250000) / 10000,
    unclassified_tier: data?.unclassified_tier || 'medium',
  })
  const [error, setError] = useState(null)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  const save = useMutation({
    mutationFn: () => api.put('/v1/token-rates/pricing', {
      paise_per_1000_tokens: Number(f.paise_per_1000_tokens),
      gst_rate: Number(f.gst_rate),
      sac: String(f.sac).trim(),
      tier_fallback_bp: {
        low: bpFromMultiplier(f.low),
        medium: bpFromMultiplier(f.medium),
        high: bpFromMultiplier(f.high),
      },
      unclassified_tier: f.unclassified_tier,
    }).then((r) => r.data?.data),
    onSuccess: (d) => { setError(null); onSaved(d) },
    onError: (e) => setError(describeError(e)),
  })

  const paise = Number(f.paise_per_1000_tokens)
  const gst = Number(f.gst_rate)
  const thousandForRs1000 = paise > 0 ? Math.floor(100000 * 1000 / paise) : 0
  const collected = paise > 0 ? Math.round(100000 * (1 + gst / 100)) : 0

  return (
    <Drawer
      open={open}
      title="Pricing & fallbacks"
      onClose={onClose}
      footer={<>
        <button className="engage-btn" onClick={onClose}>Cancel</button>
        <button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </>}
    >
      <ErrorNote error={error} />

      <div><div className="engage-label">Price per 1,000 tokens (paise, before GST)</div>
        <input className="engage-input" type="number" min="1" value={f.paise_per_1000_tokens} onChange={set('paise_per_1000_tokens')} />
      </div>
      <p className="text-xs text-neutral-500">
        100 paise = {rupees(100)} per 1,000 tokens. At this rate {rupees(100000)} of credit buys{' '}
        <strong>{thousandForRs1000.toLocaleString('en-IN')}</strong> tokens and collects{' '}
        <strong>{rupees(collected)}</strong> including {gst}% GST.
      </p>

      <div className="grid grid-cols-2 gap-3">
        <div><div className="engage-label">GST rate (%)</div>
          <input className="engage-input" type="number" min="0" max="99" step="0.01" value={f.gst_rate} onChange={set('gst_rate')} />
        </div>
        <div><div className="engage-label">SAC</div>
          <input className="engage-input font-mono" value={f.sac} onChange={set('sac')} placeholder="997331" />
        </div>
      </div>
      <p className="text-xs text-neutral-500">Finance accepts 99 followed by four digits. GST is charged on the price after any discount.</p>

      <h3 className="mt-5 mb-1 text-sm font-semibold text-neutral-700">Fallback by tier</h3>
      <p className="mb-2 text-xs text-neutral-500">
        What a model counts as when no rate is set for it. Console gives each model its tier.
      </p>
      <div className="grid grid-cols-3 gap-3">
        {['low', 'medium', 'high'].map((t) => (
          <div key={t}>
            <div className="engage-label">{TIER_LABELS[t].split(' — ')[0]}</div>
            <input className="engage-input" type="number" min="0" step="0.1" value={f[t]} onChange={set(t)} />
            <div className="mt-1 text-xs text-neutral-500">{multiplierText(bpFromMultiplier(f[t]))}</div>
          </div>
        ))}
      </div>

      <div className="mt-3"><div className="engage-label">A model Console has not classified</div>
        <select className="engage-input" value={f.unclassified_tier} onChange={set('unclassified_tier')}>
          {['low', 'medium', 'high'].map((t) => <option key={t} value={t}>{TIER_LABELS[t]}</option>)}
        </select>
      </div>
      <p className="text-xs text-neutral-500">
        Such a model is charged at this tier and listed as a warning on this page and in Console. It is never free
        — that would make an unpriced model the cheapest way to use AI — and never refused, which would take a
        product offline over a missing setting.
      </p>
    </Drawer>
  )
}
