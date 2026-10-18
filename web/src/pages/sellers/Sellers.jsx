import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'

export default function Sellers() {
  const [f, setF] = useState({ q: '', status: '', kyc_status: '' })
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()
  const q = useQuery({ queryKey: ['sellers', qs], queryFn: () => api.get(`/v1/sellers?limit=200&${qs}`).then((r) => r.data?.data) })

  return (
    <>
      <PageHeader title="Sellers" subtitle="Resellers / associates on seller.aicountly.com. Accounts and credentials live in Engage." />
      <div className="engage-card mb-3 flex flex-wrap gap-2 items-end">
        <div><div className="engage-label">Search</div><input className="engage-input" value={f.q} placeholder="Name, email, SLR code…" onChange={(e) => setF({ ...f, q: e.target.value })} /></div>
        <div><div className="engage-label">Status</div>
          <select className="engage-input" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
            <option value="">All</option>
            {['onboarding', 'kyc_pending', 'active', 'suspended', 'terminated'].map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
          </select>
        </div>
        <div><div className="engage-label">KYC</div>
          <select className="engage-input" value={f.kyc_status} onChange={(e) => setF({ ...f, kyc_status: e.target.value })}>
            <option value="">All</option>
            {['not_started', 'kyc_submitted', 'kyc_verified', 'kyc_rejected'].map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
          </select>
        </div>
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyMessage="No sellers yet. Approve an application to create one."
        columns={[
          { key: 'seller_code', header: 'Code', render: (r) => <Link className="font-mono text-xs text-aicountly-700 hover:underline" to={`/sellers/${r.id}`}>{r.seller_code}</Link> },
          { key: 'name', header: 'Seller', render: (r) => <div><Link className="font-medium text-aicountly-700 hover:underline" to={`/sellers/${r.id}`}>{r.name}</Link><div className="text-xs text-neutral-500">{r.organization || '—'}</div></div> },
          { key: 'email', header: 'Email' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'kyc_status', header: 'KYC', render: (r) => <StatusBadge status={r.kyc_status} /> },
          { key: 'is_locked', header: 'Sign-in', render: (r) => (r.is_locked ? <span className="engage-pill bg-red-50 text-red-800 border-red-200">Locked</span> : r.has_password ? 'Set' : 'Not activated') },
          { key: 'last_login_at', header: 'Last sign-in', render: (r) => formatDate(r.last_login_at, { withTime: true }) },
          { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
        ]}
      />
    </>
  )
}
