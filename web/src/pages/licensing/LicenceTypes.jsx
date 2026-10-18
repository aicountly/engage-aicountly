import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import Drawer from '../../components/Drawer.jsx'
import DataTable from '../../components/DataTable.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN, parseJsonField } from '../../lib/licensing.js'

const KINDS = [
  { value: 'paid', label: 'Paid' },
  { value: 'trial', label: 'Trial' },
  { value: 'complimentary', label: 'Complimentary' },
]

/**
 * Licence types are data: none ships with Engage. lifecycle_kind is the only fixed vocabulary
 * (what Manage's subscription understands); everything else is a label staff choose.
 */
export default function LicenceTypes() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState(null)
  const q = useQuery({ queryKey: ['licence-types'], queryFn: () => api.get('/v1/licence-types').then((r) => r.data?.data?.items || []) })
  const save = useMutation({
    mutationFn: (d) => {
      const body = {
        label: d.label, description: d.description, lifecycle_kind: d.lifecycle_kind, active: d.active,
        sort_order: Number(d.sort_order || 0), display_schema: parseJsonField(d.schemaText, 'Display schema'),
      }
      return (d.id ? api.put(`/v1/licence-types/${d.id}`, body) : api.post('/v1/licence-types', { ...body, code: d.code })).then((r) => r.data?.data)
    },
    onSuccess: () => { setDraft(null); setError(null); qc.invalidateQueries({ queryKey: ['licence-types'] }) },
    onError: (e) => setError(describeError(e)),
  })
  const edit = (t) => setDraft({ ...t, description: t.description || '', schemaText: JSON.stringify(t.display_schema || {}, null, 2) })

  return (
    <>
      <PageHeader
        title="Licence types"
        subtitle="Lookup of licence types used by offers. Nothing is pre-defined — create the types the business sells."
        actions={canEdit ? <button className="engage-btn-primary" onClick={() => { setError(null); setDraft({ code: '', label: '', description: '', lifecycle_kind: 'paid', active: true, sort_order: 0, schemaText: '{}' }) }}>+ New licence type</button> : null}
      />
      <ErrorNote error={!draft ? error : null} />
      <DataTable
        loading={q.isLoading}
        rows={q.data || []}
        emptyTitle="No licence types yet"
        emptyMessage={canEdit ? 'Create the first licence type, then build offers on it.' : 'An administrator or sales manager creates licence types.'}
        columns={[
          { key: 'code', header: 'Code', render: (t) => <span className="font-mono text-xs">{t.code}</span> },
          { key: 'label', header: 'Label' },
          { key: 'lifecycle_kind', header: 'Lifecycle', render: (t) => KINDS.find((k) => k.value === t.lifecycle_kind)?.label || t.lifecycle_kind },
          { key: 'active', header: 'Active', render: (t) => (t.active ? 'Yes' : 'No') },
          { key: 'offers_count', header: 'Offers', align: 'right' },
          { key: 'updated_at', header: 'Updated', render: (t) => formatDateIN(t.updated_at) },
          ...(canEdit ? [{ key: 'actions', header: '', render: (t) => <button className="engage-btn-secondary text-xs" onClick={() => { setError(null); edit(t) }}>Edit</button> }] : []),
        ]}
      />
      <Drawer open={!!draft} title={draft?.id ? `Edit ${draft.code}` : 'New licence type'} onClose={() => setDraft(null)}
        footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate(draft)}>Save</button>}>
        {draft ? <>
          <ErrorNote error={error} />
          {!draft.id ? <div><div className="engage-label">Code (permanent)</div><input className="engage-input font-mono" value={draft.code} placeholder="e.g. standard" onChange={(e) => setDraft({ ...draft, code: e.target.value })} /></div> : null}
          <div><div className="engage-label">Label</div><input className="engage-input" value={draft.label} onChange={(e) => setDraft({ ...draft, label: e.target.value })} /></div>
          <div><div className="engage-label">Description</div><input className="engage-input" value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} /></div>
          <div>
            <div className="engage-label">Lifecycle kind</div>
            <select className="engage-input" value={draft.lifecycle_kind} disabled={!!draft.id && draft.offers_count > 0} onChange={(e) => setDraft({ ...draft, lifecycle_kind: e.target.value })}>
              {KINDS.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
            </select>
            {draft.id && draft.offers_count > 0 ? <div className="text-xs text-neutral-500 mt-1">Offers use this type, so its lifecycle kind is fixed.</div> : null}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!draft.active} onChange={(e) => setDraft({ ...draft, active: e.target.checked })} /> Active</label>
            <div><div className="engage-label">Sort order</div><input className="engage-input" type="number" value={draft.sort_order} onChange={(e) => setDraft({ ...draft, sort_order: e.target.value })} /></div>
          </div>
          <div>
            <div className="engage-label">Display schema (JSON object, optional — Manage renders it as given)</div>
            <textarea className="engage-input font-mono text-xs" rows={6} value={draft.schemaText} onChange={(e) => setDraft({ ...draft, schemaText: e.target.value })} />
          </div>
        </> : null}
      </Drawer>
    </>
  )
}
