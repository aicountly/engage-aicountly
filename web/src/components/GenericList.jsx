import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from './PageHeader.jsx'
import FilterBar from './FilterBar.jsx'
import DataTable from './DataTable.jsx'
import Drawer from './Drawer.jsx'
import { formatDate } from '../lib/format.js'

/**
 * Simple CRUD-ish list for endpoints under /api/v1/<resource>.
 * Props:
 *   title, subtitle, resource, columns, filters (optional), formFields (optional),
 *   canCreate (default true), canEdit (default true), extraActions (fn(row) -> JSX)
 */
export default function GenericList({
  title,
  subtitle,
  resource,
  columns,
  filters = [],
  defaultFilters = {},
  formFields = null,
  canCreate = true,
  canEdit = true,
  emptyMessage,
  actions,
  transform,
}) {
  const qc = useQueryClient()
  const [f, setF] = useState(defaultFilters)
  const [drawer, setDrawer] = useState(null) // null | { mode: 'create' } | { mode: 'edit', row }
  const [error, setError] = useState(null)

  const query = useMemo(() => {
    const cleaned = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null))
    return new URLSearchParams(cleaned).toString()
  }, [f])

  const listQ = useQuery({
    queryKey: [resource, query],
    queryFn: () => api.get(`/v1/${resource}?${query}`).then((r) => r.data?.data),
    keepPreviousData: true,
  })

  const create = useMutation({
    mutationFn: (payload) => api.post(`/v1/${resource}`, payload).then((r) => r.data),
    onSuccess: () => { setDrawer(null); qc.invalidateQueries({ queryKey: [resource] }) },
    onError: (e) => setError(apiError(e)),
  })
  const update = useMutation({
    mutationFn: ({ id, payload }) => api.put(`/v1/${resource}/${id}`, payload).then((r) => r.data),
    onSuccess: () => { setDrawer(null); qc.invalidateQueries({ queryKey: [resource] }) },
    onError: (e) => setError(apiError(e)),
  })

  let rows = listQ.data?.rows || listQ.data?.items || (Array.isArray(listQ.data) ? listQ.data : [])
  if (transform) rows = transform(rows, listQ.data)

  return (
    <>
      <PageHeader
        title={title}
        subtitle={subtitle}
        actions={
          <>
            {actions}
            {canCreate && formFields ? (
              <button className="engage-btn-primary" onClick={() => { setError(null); setDrawer({ mode: 'create' }) }}>+ New</button>
            ) : null}
          </>
        }
      />
      {filters.length ? <FilterBar filters={filters} value={f} onChange={setF} /> : null}
      <DataTable
        columns={columns}
        rows={rows}
        loading={listQ.isLoading}
        emptyMessage={emptyMessage || 'No records yet.'}
        onRowClick={canEdit && formFields ? (r) => { setError(null); setDrawer({ mode: 'edit', row: r }) } : undefined}
      />

      {drawer ? (
        <Drawer
          open
          title={drawer.mode === 'create' ? `New ${title.replace(/s$/, '')}` : `Edit ${title.replace(/s$/, '')}`}
          onClose={() => setDrawer(null)}
        >
          <RowForm
            initial={drawer.row || {}}
            fields={formFields}
            error={error}
            submitting={create.isPending || update.isPending}
            onSubmit={(payload) => {
              setError(null)
              drawer.mode === 'create'
                ? create.mutate(payload)
                : update.mutate({ id: drawer.row.id, payload })
            }}
          />
          {drawer.mode === 'edit' && drawer.row?.created_at ? (
            <div className="mt-4 text-xs text-neutral-500">Created {formatDate(drawer.row.created_at, { withTime: true })}</div>
          ) : null}
        </Drawer>
      ) : null}
    </>
  )
}

function RowForm({ initial, fields, onSubmit, submitting, error }) {
  const [state, setState] = useState(() => {
    const s = {}
    for (const f of fields) s[f.key] = initial?.[f.key] ?? f.default ?? ''
    return s
  })

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault()
        const clean = {}
        for (const f of fields) {
          let v = state[f.key]
          if (v === '' && f.type !== 'boolean') v = null
          if (f.type === 'number' && v != null && v !== '') v = Number(v)
          if (f.type === 'boolean') v = !!v
          clean[f.key] = v
        }
        onSubmit(clean)
      }}
    >
      {fields.map((f) => (
        <div key={f.key}>
          <label className="engage-label">{f.label}{f.required ? ' *' : ''}</label>
          {f.type === 'textarea' ? (
            <textarea className="engage-input" rows={f.rows || 3} value={state[f.key] ?? ''} onChange={(e) => setState({ ...state, [f.key]: e.target.value })} required={f.required} />
          ) : f.type === 'select' ? (
            <select className="engage-input" value={state[f.key] ?? ''} onChange={(e) => setState({ ...state, [f.key]: e.target.value })} required={f.required}>
              <option value="">— choose —</option>
              {(f.options || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          ) : f.type === 'boolean' ? (
            <label className="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!state[f.key]} onChange={(e) => setState({ ...state, [f.key]: e.target.checked })} />
              <span className="text-neutral-700">{f.checkboxLabel || 'Enabled'}</span>
            </label>
          ) : (
            <input className="engage-input" type={f.type || 'text'} value={state[f.key] ?? ''} onChange={(e) => setState({ ...state, [f.key]: e.target.value })} required={f.required} placeholder={f.placeholder || ''} />
          )}
          {f.help ? <div className="text-xs text-neutral-500 mt-1">{f.help}</div> : null}
        </div>
      ))}
      {error ? <div className="rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      <div className="pt-2 flex justify-end">
        <button type="submit" disabled={submitting} className="engage-btn-primary">
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </form>
  )
}
