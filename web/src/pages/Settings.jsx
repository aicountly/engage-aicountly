import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'

export default function Settings() {
  const qc = useQueryClient()
  const [msg, setMsg] = useState(null)
  const [drafts, setDrafts] = useState({})

  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get('/v1/settings').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  useEffect(() => {
    if (Array.isArray(data)) {
      const d = {}
      data.forEach((s) => { d[s.key] = typeof s.value === 'string' ? s.value : JSON.stringify(s.value, null, 2) })
      setDrafts(d)
    }
  }, [data])

  const save = useMutation({
    mutationFn: ({ key, value }) => api.put('/v1/settings', { key, value }).then((r) => r.data),
    onSuccess: () => { setMsg({ ok: true, text: 'Saved.' }); qc.invalidateQueries({ queryKey: ['settings'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  const grouped = useMemo(() => {
    const g = {}
    ;(data || []).forEach((s) => {
      const grp = s.group || s.category || 'general'
      g[grp] = g[grp] || []
      g[grp].push(s)
    })
    return g
  }, [data])

  return (
    <>
      <PageHeader
        title="Settings"
        subtitle="System-wide flags. Only superadmins can modify."
      />
      {msg ? <div className={`mb-3 rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>{msg.text}</div> : null}

      {isLoading ? <div>Loading…</div> : (
        <div className="space-y-6">
          {Object.entries(grouped).map(([g, items]) => (
            <div key={g} className="engage-card">
              <div className="text-sm font-semibold text-neutral-900 mb-3 capitalize">{g}</div>
              <div className="space-y-3">
                {items.map((s) => (
                  <div key={s.key} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
                    <div>
                      <div className="text-sm font-medium text-neutral-900">{s.key}</div>
                      <div className="text-xs text-neutral-500">{s.description || ''}</div>
                    </div>
                    <div className="md:col-span-2">
                      <textarea
                        rows={typeof s.value === 'object' ? 4 : 1}
                        className="engage-input font-mono text-xs"
                        value={drafts[s.key] ?? ''}
                        onChange={(e) => setDrafts({ ...drafts, [s.key]: e.target.value })}
                      />
                      <div className="mt-1 flex justify-end">
                        <button
                          className="engage-btn-primary text-xs"
                          onClick={() => {
                            let v = drafts[s.key]
                            try { v = JSON.parse(drafts[s.key]) } catch { /* keep as string */ }
                            save.mutate({ key: s.key, value: v })
                          }}
                          disabled={save.isPending}
                        >Save</button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}
