import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import IssueLicenceDrawer from '../../components/IssueLicenceDrawer.jsx'
import { LicenceStatusBadge } from '../../components/LicensingBits.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN, lastDayIN } from '../../lib/licensing.js'

export default function Licences() {
  const nav = useNavigate()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [params, setParams] = useSearchParams()
  const [issuing, setIssuing] = useState(false)
  const filters = { q: params.get('q') || '', cmp_id: params.get('cmp_id') || '', account_id: params.get('account_id') || '', status: params.get('status') || '' }
  const q = useQuery({
    queryKey: ['licences', filters],
    queryFn: () => api.get('/v1/licences', { params: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')) }).then((r) => r.data?.data?.items || []),
  })
  const setFilter = (k, v) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p, { replace: true }) }

  return (
    <>
      <PageHeader
        title="Licences"
        subtitle="Licences issued to Manage companies. Customers see them in Manage (read only) once Manage is connected."
        actions={canEdit ? <button className="engage-btn-primary" onClick={() => setIssuing(true)}>+ Issue licence</button> : null}
      />
      <div className="engage-card mb-3 grid grid-cols-1 md:grid-cols-4 gap-2">
        <input className="engage-input" placeholder="Search ref, account, offer…" value={filters.q} onChange={(e) => setFilter('q', e.target.value)} />
        <input className="engage-input font-mono" placeholder="cmp_id" inputMode="numeric" value={filters.cmp_id} onChange={(e) => setFilter('cmp_id', e.target.value.replace(/\D/g, ''))} />
        <input className="engage-input font-mono" placeholder="account id" inputMode="numeric" value={filters.account_id} onChange={(e) => setFilter('account_id', e.target.value.replace(/\D/g, ''))} />
        <select className="engage-input" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">Any status</option>
          {['issued', 'active', 'suspended', 'expired', 'revoked'].map((s) => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
        </select>
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data || []}
        onRowClick={(l) => nav(`/licences/${l.licence_ref}`)}
        emptyTitle="No licences"
        emptyMessage="Issue a licence from a published offer to a mapped Manage company."
        columns={[
          { key: 'licence_ref', header: 'Licence', render: (l) => <span className="font-mono text-xs">{l.licence_ref}</span> },
          { key: 'account', header: 'Account', render: (l) => l.account?.name },
          { key: 'cmp_id', header: 'cmp_id', render: (l) => <span className="font-mono">{l.cmp_id}</span> },
          { key: 'offer', header: 'Offer', render: (l) => <>{l.offer.label} <span className="font-mono text-xs text-neutral-500">v{l.offer.version}</span></> },
          { key: 'status', header: 'Status', render: (l) => <LicenceStatusBadge status={l.status} /> },
          { key: 'term', header: 'Term', nowrap: true, render: (l) => `${formatDateIN(l.term.starts_at)} – ${lastDayIN(l.term.ends_at)}` },
          { key: 'seats', header: 'Seats', align: 'right', render: (l) => `${l.seats.assigned} / ${l.seats.total ?? '∞'}` },
        ]}
      />
      <IssueLicenceDrawer
        open={issuing}
        cmpId={filters.cmp_id || null}
        accountId={filters.account_id || null}
        onClose={() => setIssuing(false)}
        onIssued={(l) => { setIssuing(false); nav(`/licences/${l.licence_ref}`) }}
      />
    </>
  )
}
