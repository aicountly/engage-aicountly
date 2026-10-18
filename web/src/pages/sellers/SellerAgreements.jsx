import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { SELLER_ADMIN } from '../../lib/sellerRoles.js'

/** Seller agreement text (plain text). Exactly one version is current; sellers accept it to finish activation. */
export default function SellerAgreements() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(SELLER_ADMIN)
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState(null)
  const q = useQuery({ queryKey: ['seller-agreements'], queryFn: () => api.get('/v1/seller-agreements').then((r) => r.data?.data?.items || []) })
  const save = useMutation({
    mutationFn: (v) => (v.id ? api.put(`/v1/seller-agreements/${v.id}`, v) : api.post('/v1/seller-agreements', v)).then((r) => r.data?.data),
    onSuccess: () => { setDraft(null); setError(null); qc.invalidateQueries({ queryKey: ['seller-agreements'] }) },
    onError: (e) => setError(apiError(e)),
  })
  const publish = useMutation({
    mutationFn: (id) => api.post(`/v1/seller-agreements/${id}/publish`).then((r) => r.data?.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['seller-agreements'] }),
    onError: (e) => setError(apiError(e)),
  })

  return (
    <>
      <PageHeader
        title="Seller agreement"
        subtitle="Publishing a new version asks every seller to accept it before further changes in the portal."
        actions={canEdit ? <button className="engage-btn-primary" onClick={() => setDraft({ version_label: '', title: 'AICOUNTLY Seller Agreement', body: '' })}>+ New version</button> : null}
      />
      {error ? <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      {(q.data || []).length === 0 ? <div className="engage-card text-sm text-neutral-500">No agreement yet. Sellers cannot finish activation until one is published.</div> : null}
      <div className="space-y-3">
        {(q.data || []).map((v) => (
          <div key={v.id} className="engage-card">
            <div className="flex items-center justify-between">
              <div><span className="font-semibold">{v.title}</span> <span className="font-mono text-xs">v{v.version_label}</span> <StatusBadge status={v.status === 'current' ? 'active' : v.status} /></div>
              <div className="flex gap-2 items-center text-xs text-neutral-500">
                {v.published_at ? `Published ${formatDate(v.published_at)} · ` : ''}{v.acceptances} acceptance(s)
                {canEdit && v.status === 'draft' ? <>
                  <button className="engage-btn-secondary text-xs" onClick={() => setDraft(v)}>Edit</button>
                  <button className="engage-btn-primary text-xs" onClick={() => { if (window.confirm('Publish this version? It replaces the current agreement.')) publish.mutate(v.id) }}>Publish</button>
                </> : null}
              </div>
            </div>
            <pre className="mt-2 whitespace-pre-wrap text-xs text-neutral-700 max-h-48 overflow-y-auto">{v.body}</pre>
          </div>
        ))}
      </div>
      <Drawer open={!!draft} title={draft?.id ? 'Edit draft' : 'New agreement version'} onClose={() => setDraft(null)}
        footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate(draft)}>Save draft</button>}>
        {draft ? <>
          <div><div className="engage-label">Version label</div><input className="engage-input" value={draft.version_label} placeholder="2026.1" onChange={(e) => setDraft({ ...draft, version_label: e.target.value })} /></div>
          <div><div className="engage-label">Title</div><input className="engage-input" value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} /></div>
          <div><div className="engage-label">Text (plain text — paragraphs separated by a blank line)</div><textarea className="engage-input font-mono text-xs" rows={18} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} /></div>
        </> : null}
      </Drawer>
    </>
  )
}
