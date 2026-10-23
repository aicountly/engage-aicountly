import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import DataTable from './DataTable.jsx'
import IssueLicenceDrawer from './IssueLicenceDrawer.jsx'
import { LicenceStatusBadge } from './LicensingBits.jsx'
import { useAuth } from '../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN, lastDayIN } from '../lib/licensing.js'

/** Licences of one account (Account page). */
export default function AccountLicencesPanel({ accountId }) {
  const nav = useNavigate()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [issuing, setIssuing] = useState(false)
  const q = useQuery({ queryKey: ['licences', { account_id: String(accountId) }], queryFn: () => api.get('/v1/licences', { params: { account_id: accountId } }).then((r) => r.data?.data?.items || []) })

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <div className="text-sm font-semibold">Licences</div>
        {canEdit ? <button className="engage-btn-secondary text-xs" onClick={() => setIssuing(true)}>+ Issue licence</button> : null}
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data || []}
        onRowClick={(l) => nav(`/licences/${l.licence_ref}`)}
        emptyMessage="No licences for this account."
        columns={[
          { key: 'licence_ref', header: 'Licence', render: (l) => <span className="font-mono text-xs">{l.licence_ref}</span> },
          { key: 'holder', header: 'Holder', render: (l) => <span className="font-mono text-xs">{l.holder_uuid || '—'}</span> },
          { key: 'cmp_id', header: 'cmp_id', render: (l) => <span className="font-mono">{l.cmp_id ?? '—'}</span> },
          { key: 'offer', header: 'Offer', render: (l) => l.offer.label },
          { key: 'status', header: 'Status', render: (l) => <LicenceStatusBadge status={l.status} /> },
          { key: 'term', header: 'Term', nowrap: true, render: (l) => `${formatDateIN(l.term.starts_at)} – ${lastDayIN(l.term.ends_at)}` },
        ]}
      />
      <IssueLicenceDrawer open={issuing} accountId={accountId} onClose={() => setIssuing(false)} onIssued={(l) => { setIssuing(false); nav(`/licences/${l.licence_ref}`) }} />
    </div>
  )
}
