import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'

// Product names → Manage keys, as the server folds them (ManageAppRegistry::ALIASES).
const ALIASES = { drive: 'docs', connect: 'chat', pulse: 'buddy', lobby: 'receptionist', purchase: 'purchases', ourpeople: 'hrms', smartbooks: 'books', 'smart-books': 'books' }

/** "sales, Drive" → ['sales', 'docs'] */
export function appKeysOf(text, productCode) {
  const raw = String(text || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean)
  const keys = raw.length ? raw : (productCode ? [String(productCode).toLowerCase()] : [])
  return [...new Set(keys.map((k) => ALIASES[k] || k))]
}

/** '' → null, 'true' / 'false' → boolean, '25' → 25, anything else stays text. */
export function parseEntitlementValue(v) {
  const s = String(v ?? '').trim()
  if (s === '') return null
  if (s === 'true' || s === 'false') return s === 'true'
  if (/^-?\d+(\.\d+)?$/.test(s)) return Number(s)
  return s
}

/**
 * Adds or updates one {code, label, value, unit} row in the offer's entitlements JSON from the known codes
 * (GET /v1/entitlement-codes). Typing a code the catalogue does not know is still allowed — the row then gets the
 * code as its label and no unit, and staff can edit the JSON below as before.
 */
export default function EntitlementCodePicker({ text, onChange, apps = [] }) {
  const [code, setCode] = useState('')
  const [value, setValue] = useState('')
  const [allApps, setAllApps] = useState(false)
  const [note, setNote] = useState(null)
  const codes = useQuery({
    queryKey: ['entitlement-codes'],
    queryFn: () => api.get('/v1/entitlement-codes').then((r) => r.data?.data?.items || []),
    staleTime: 10 * 60 * 1000,
  })

  const all = codes.data || []
  const shown = useMemo(
    () => (allApps || apps.length === 0 ? all : all.filter((c) => c.app === null || apps.includes(c.app))),
    [all, apps, allApps],
  )
  const known = all.find((c) => c.code === code.trim().toLowerCase())

  let current = null
  try {
    const parsed = JSON.parse(text || '[]')
    current = Array.isArray(parsed) ? parsed : null
  } catch {
    current = null
  }
  const unknown = (current || []).map((e) => String(e?.code || '')).filter((c) => c && all.length && !all.some((k) => k.code === c))

  const add = () => {
    const c = code.trim().toLowerCase()
    if (!/^[a-z0-9][a-z0-9_.-]{0,63}$/.test(c)) {
      setNote('A code is 1–64 lowercase letters, digits, . - or _ (e.g. sales.max_orders_per_month).')
      return
    }
    if (current === null) {
      setNote('Fix the JSON below first (it must be a list), then add the code.')
      return
    }
    const row = { code: c, label: known?.label || c, value: parseEntitlementValue(value), unit: known?.unit ?? null }
    const i = current.findIndex((e) => e?.code === c)
    const next = i >= 0 ? current.map((e, j) => (j === i ? { ...e, value: row.value } : e)) : [...current, row]
    onChange(JSON.stringify(next, null, 2))
    setNote(i >= 0 ? `Updated ${c}.` : known ? null : `${c} is not in the catalogue — added as typed; check the product enforces it.`)
    setCode('')
    setValue('')
  }

  return (
    <div className="rounded border border-neutral-200 p-2 space-y-2" data-testid="entitlement-code-picker">
      <div className="grid grid-cols-1 md:grid-cols-[1fr_8rem_auto] gap-2 items-end">
        <div>
          <div className="engage-label">Limit or feature code</div>
          <input
            className="engage-input font-mono text-xs"
            list="engage-entitlement-codes"
            placeholder={codes.isLoading ? 'Loading codes…' : 'Pick or type a code'}
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
          <datalist id="engage-entitlement-codes">
            {shown.map((c) => (
              <option key={c.code} value={c.code}>{`${c.label}${c.app ? ` · ${c.app}` : ' · Manage, every app'} (${c.unit})`}</option>
            ))}
          </datalist>
        </div>
        <div>
          <div className="engage-label">Value{known ? ` (${known.unit})` : ''}</div>
          <input className="engage-input text-xs" placeholder={known?.unit === 'bool' ? 'true / false' : 'e.g. 25'} value={value} onChange={(e) => setValue(e.target.value)} />
        </div>
        <button type="button" className="engage-btn-secondary text-xs" onClick={add} disabled={!code.trim()}>Add / update</button>
      </div>
      <div className="flex flex-wrap items-center gap-3 text-xs text-neutral-500">
        {known ? <span>{known.label} — {known.enforced_by === 'manage' ? 'enforced by Manage for every app' : `enforced by ${known.app}`}</span> : null}
        <label className="flex items-center gap-1">
          <input type="checkbox" checked={allApps} onChange={(e) => setAllApps(e.target.checked)} /> show codes of every app
          {apps.length && !allApps ? ` (now: Manage + ${apps.join(', ')})` : ''}
        </label>
        {codes.isError ? <span className="text-amber-700">The code list could not be loaded — type the code.</span> : null}
      </div>
      {note ? <div className="text-xs text-neutral-600">{note}</div> : null}
      {unknown.length ? <div className="text-xs text-amber-700">Not in the catalogue (allowed): {unknown.join(', ')}</div> : null}
    </div>
  )
}
