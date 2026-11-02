import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import api from '../../lib/api.js'
import Drawer from '../../components/Drawer.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { multiplierText, bpFromMultiplier } from '../../lib/tokens.js'

/**
 * A new token rate. Always a draft: someone else activates it.
 *
 * Opened either blank (+ New rate) or pre-filled from a row in the model table, which is the
 * common path — the model key has to match Console exactly, and typing it again is how it ends up
 * not matching.
 */
export default function TokenRateForm({ open, initial, onClose, onSaved }) {
  const [f, setF] = useState({
    provider_code: initial?.provider_code || '',
    model_key: initial?.model_key || '',
    multiplier: initial?.multiplier || '',
    label: initial?.label || '',
    notes: '',
  })
  const [error, setError] = useState(null)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  const save = useMutation({
    mutationFn: () => api.post('/v1/token-rates', {
      provider_code: f.provider_code.trim(),
      model_key: f.model_key.trim(),
      multiplier: f.multiplier,
      label: f.label.trim(),
      notes: f.notes.trim() || null,
    }).then((r) => r.data?.data),
    onSuccess: (d) => { setError(null); onSaved(d) },
    onError: (e) => setError(describeError(e)),
  })

  const bp = bpFromMultiplier(f.multiplier)
  const valid = f.provider_code.trim() !== '' && f.label.trim() !== '' && bp !== null && bp >= 0 && bp <= 10000000

  return (
    <Drawer
      open={open}
      title="New token rate"
      onClose={onClose}
      footer={<>
        <button className="engage-btn" onClick={onClose}>Cancel</button>
        <button className="engage-btn-primary" disabled={!valid || save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save draft'}
        </button>
      </>}
    >
      <ErrorNote error={error} />

      <div><div className="engage-label">Provider</div>
        <input className="engage-input" value={f.provider_code} onChange={set('provider_code')} placeholder="anthropic" />
      </div>
      <p className="text-xs text-neutral-500">The provider slug exactly as Console holds it.</p>

      <div><div className="engage-label">Model</div>
        <input className="engage-input font-mono" value={f.model_key} onChange={set('model_key')} placeholder="claude-sonnet-5" />
      </div>
      <p className="text-xs text-neutral-500">
        The model id exactly as Console holds it. Leave it blank to set this provider&rsquo;s default, which covers
        every model of theirs that has no rate of its own.
      </p>

      <div><div className="engage-label">Counts as</div>
        <input className="engage-input" type="number" min="0" step="0.1" value={f.multiplier} onChange={set('multiplier')} placeholder="10" />
      </div>
      <p className="text-xs text-neutral-500">
        How many times each token counts against the customer&rsquo;s allowance.
        {bp !== null && f.multiplier !== '' ? <> This rate reads as <strong>{multiplierText(bp)}</strong>: 1,000 tokens would be charged
          as <strong>{Math.round(1000 * bp / 10000).toLocaleString('en-IN')}</strong>.</> : null}
      </p>

      <div><div className="engage-label">Name</div>
        <input className="engage-input" value={f.label} onChange={set('label')} placeholder="Sonnet 5 — general purpose" />
      </div>

      <div><div className="engage-label">Notes</div>
        <textarea className="engage-input" rows={3} value={f.notes} onChange={set('notes')} placeholder="Why this rate, and what it is based on." />
      </div>

      <p className="mt-4 text-xs text-neutral-500">
        Saved as a draft. Another sales manager or an administrator activates it, and from then on it cannot be
        edited — a rate that has priced real usage is retired and replaced, never changed.
      </p>
    </Drawer>
  )
}
