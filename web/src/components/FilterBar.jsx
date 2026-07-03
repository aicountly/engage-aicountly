import { useState } from 'react'

export default function FilterBar({ filters = [], value = {}, onChange, actions }) {
  const [draft, setDraft] = useState(value)

  function set(k, v) {
    const next = { ...draft, [k]: v }
    setDraft(next)
    onChange?.(next)
  }

  return (
    <div className="engage-card p-3 mb-4">
      <div className="flex flex-wrap items-end gap-3">
        {filters.map((f) => (
          <div key={f.key} className="flex-1 min-w-[160px]">
            <label className="engage-label">{f.label}</label>
            {f.type === 'select' ? (
              <select className="engage-input" value={draft[f.key] ?? ''} onChange={(e) => set(f.key, e.target.value)}>
                <option value="">All</option>
                {(f.options || []).map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            ) : (
              <input
                className="engage-input"
                type={f.type || 'text'}
                placeholder={f.placeholder || ''}
                value={draft[f.key] ?? ''}
                onChange={(e) => set(f.key, e.target.value)}
              />
            )}
          </div>
        ))}
        {actions ? <div className="ml-auto flex items-end gap-2">{actions}</div> : null}
      </div>
    </div>
  )
}
