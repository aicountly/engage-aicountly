import { useSearchParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { LICENSING_ADMIN, OUTBOX_TONES, TONE_CLASSES, formatDateIN } from '../../lib/licensing.js'

/**
 * Engage → Manage entitlement push (docs/LICENSING.md § 6): every licence change queues the holder's full plan state
 * per app; engage:entitlements-push sends it every minute, in seq order per holder. Rejected / dead rows need a look.
 */
export default function ManageSync() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [params, setParams] = useSearchParams()
  const filters = { status: params.get('status') || '', holder_uuid: params.get('holder_uuid') || '' }
  const q = useQuery({
    queryKey: ['entitlement-outbox', filters],
    queryFn: () => api.get('/v1/entitlements/outbox', { params: Object.fromEntries(Object.entries({ ...filters, limit: 200 }).filter(([, v]) => v !== '')) }).then((r) => r.data?.data),
    refetchInterval: 30000,
  })
  const requeue = useMutation({
    mutationFn: (id) => api.post(`/v1/entitlements/outbox/${id}/requeue`).then((r) => r.data?.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entitlement-outbox'] }),
  })
  const setFilter = (k, v) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p, { replace: true }) }
  const s = q.data?.summary || {}

  return (
    <>
      <PageHeader title="Manage sync" subtitle="Plan states pushed to Manage. A rejected or dead row means Manage does not have the holder's current plan — fix the cause, then re-queue." />
      <ErrorNote error={requeue.error ? describeError(requeue.error) : null} />
      {q.data && !q.data.manage?.configured ? (
        <div className="mb-3 rounded-md bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">{q.data.manage.status}</div>
      ) : null}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-2 mb-3">
        {['pending', 'delivered', 'rejected', 'dead', 'skipped'].map((k) => (
          <button key={k} type="button" onClick={() => setFilter('status', filters.status === k ? '' : k)}
            className={clsx('engage-card text-left', filters.status === k && 'ring-2 ring-aicountly-300')}>
            <div className="text-xs text-neutral-500 capitalize">{k}</div>
            <div className={clsx('text-xl font-semibold', (k === 'dead' || k === 'rejected') && s[k] ? 'text-red-700' : 'text-neutral-900')}>{s[k] ?? 0}</div>
          </button>
        ))}
      </div>
      <div className="engage-card mb-3">
        <input className="engage-input font-mono" placeholder="holder uuid" value={filters.holder_uuid} onChange={(e) => setFilter('holder_uuid', e.target.value.trim())} />
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyTitle="Nothing queued"
        emptyMessage="Licence changes appear here as they are queued for Manage."
        columns={[
          { key: 'status', header: 'Status', render: (r) => <span className={clsx('engage-pill', TONE_CLASSES[OUTBOX_TONES[r.status]] || TONE_CLASSES.neutral)}>{r.status}</span> },
          { key: 'holder', header: 'Holder', render: (r) => <span className="font-mono text-xs">{r.holder_uuid || `cmp ${r.cmp_id}`}</span> },
          { key: 'app', header: 'App · seq', render: (r) => <span className="font-mono text-xs">{r.app_key} #{r.seq}</span> },
          { key: 'state', header: 'State', render: (r) => <>{r.payload?.status}{r.payload?.plan ? ` · ${r.payload.plan.label}${r.payload.plan.is_default ? ' (Basic)' : ''}` : ''}</> },
          { key: 'licence', header: 'Licence', render: (r) => (r.licence_ref ? <Link className="font-mono text-xs text-aicountly-700 hover:underline" to={`/licences/${r.licence_ref}`}>{r.licence_ref}</Link> : '—') },
          { key: 'reason', header: 'Cause', render: (r) => <span className="text-xs">{r.reason}</span> },
          { key: 'attempts', header: 'Tries', align: 'right', render: (r) => r.attempts },
          { key: 'when', header: 'Queued', nowrap: true, render: (r) => formatDateIN(r.created_at, { withTime: true }) },
          { key: 'error', header: 'Last error', render: (r) => <span className="text-xs text-red-700">{r.last_error || ''}</span> },
          { key: 'act', header: '', render: (r) => (canEdit && ['rejected', 'dead'].includes(r.status)
            ? <button className="engage-btn-secondary text-xs" disabled={requeue.isPending} onClick={() => { if (window.confirm('Queue this holder\'s CURRENT plan state for this app again?')) requeue.mutate(r.id) }}>Re-queue</button> : null) },
        ]}
      />
    </>
  )
}
