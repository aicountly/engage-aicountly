import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import Drawer from '../../components/Drawer.jsx'
import OneTimeLink from '../../components/OneTimeLink.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { ErrorNote, describeError } from '../../components/LicensingBits.jsx'
import { formatDate, titleCase } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { SELLER_ADMIN } from '../../lib/sellerRoles.js'

const ENTITY_TYPES = ['individual', 'proprietorship', 'partnership', 'llp', 'company']
const NEW_SELLER = { name: '', email: '', mobile: '', business_name: '', entity_type: '', city: '', state: '', note: '' }

/**
 * Staff add a seller directly, without a request to join (docs/SELLING_LICENCES.md §7): the same seller an approved
 * application creates — then activation, agreement and KYC as usual.
 */
function AddSellerDrawer({ open, onClose, onCreated }) {
  const [f, setF] = useState(NEW_SELLER)
  const [error, setError] = useState(null)
  const save = useMutation({
    mutationFn: () => api.post('/v1/sellers', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))).then((r) => r.data?.data),
    onSuccess: (d) => { setF(NEW_SELLER); setError(null); onCreated(d) },
    onError: (e) => setError(describeError(e)),
  })
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  return (
    <Drawer open={open} title="Add a seller" onClose={onClose} size="md"
      footer={<button className="engage-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>Add seller and send activation</button>}>
      <ErrorNote error={error} />
      <div className="text-xs text-neutral-500">For someone you have already vetted. They get an activation link, accept the seller agreement and complete KYC like any approved applicant. If they already sent a request to join, approve that instead.</div>
      <div className="grid grid-cols-2 gap-3">
        <div className="col-span-2"><div className="engage-label">Name</div><input className="engage-input" value={f.name} onChange={set('name')} /></div>
        <div><div className="engage-label">Email</div><input className="engage-input" type="email" value={f.email} onChange={set('email')} /></div>
        <div><div className="engage-label">Mobile</div><input className="engage-input" value={f.mobile} onChange={set('mobile')} placeholder="+91…" /></div>
        <div><div className="engage-label">Business name (optional)</div><input className="engage-input" value={f.business_name} onChange={set('business_name')} /></div>
        <div><div className="engage-label">Entity type (optional)</div>
          <select className="engage-input" value={f.entity_type} onChange={set('entity_type')}>
            <option value="">—</option>{ENTITY_TYPES.map((t) => <option key={t} value={t}>{titleCase(t)}</option>)}
          </select></div>
        <div><div className="engage-label">City (optional)</div><input className="engage-input" value={f.city} onChange={set('city')} /></div>
        <div><div className="engage-label">State (optional)</div><input className="engage-input" value={f.state} onChange={set('state')} /></div>
        <div className="col-span-2"><div className="engage-label">Internal note (optional)</div><textarea className="engage-input" rows={2} value={f.note} onChange={set('note')} placeholder="How you know them, territory…" /></div>
      </div>
    </Drawer>
  )
}

export default function Sellers() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const [adding, setAdding] = useState(false)
  const [created, setCreated] = useState(null)
  const [f, setF] = useState({ q: '', status: '', kyc_status: '' })
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()
  const q = useQuery({ queryKey: ['sellers', qs], queryFn: () => api.get(`/v1/sellers?limit=200&${qs}`).then((r) => r.data?.data) })

  return (
    <>
      <PageHeader title="Sellers" subtitle="Resellers / associates on seller.aicountly.com. Accounts and credentials live in Engage."
        actions={hasRole(SELLER_ADMIN) ? <button className="engage-btn-primary" onClick={() => setAdding(true)}>+ Add seller</button> : null} />
      {created ? (
        <div className="engage-card mb-3 text-sm">
          <div className="mb-2">Seller <Link className="font-mono text-aicountly-700 hover:underline" to={`/sellers/${created.seller.id}`}>{created.seller.seller_code}</Link> created for {created.seller.name}.</div>
          {(created.warnings || []).map((w) => <div key={w} className="mb-2 text-amber-800">{w}</div>)}
          <OneTimeLink delivery={created.activation} onDismiss={() => setCreated(null)} />
        </div>
      ) : null}
      <AddSellerDrawer open={adding} onClose={() => setAdding(false)} onCreated={(d) => { setAdding(false); setCreated(d); qc.invalidateQueries({ queryKey: ['sellers'] }) }} />
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
        emptyMessage="No sellers yet. Approve an application, or add a seller directly."
        columns={[
          { key: 'seller_code', header: 'Code', render: (r) => <Link className="font-mono text-xs text-aicountly-700 hover:underline" to={`/sellers/${r.id}`}>{r.seller_code}</Link> },
          { key: 'name', header: 'Seller', render: (r) => <div><Link className="font-medium text-aicountly-700 hover:underline" to={`/sellers/${r.id}`}>{r.name}</Link><div className="text-xs text-neutral-500">{r.organization || '—'}</div></div> },
          { key: 'email', header: 'Email' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'kyc_status', header: 'KYC', render: (r) => <StatusBadge status={r.kyc_status} /> },
          { key: 'is_locked', header: 'Sign-in', render: (r) => (r.is_locked ? <span className="engage-pill bg-red-50 text-red-800 border-red-200">Locked</span> : r.has_password ? 'Set' : 'Not activated') },
          { key: 'last_login_at', header: 'Last sign-in', render: (r) => formatDate(r.last_login_at, { withTime: true }) },
          { key: 'created_at', header: 'Created', render: (r) => <div>{formatDate(r.created_at)}<div className="text-xs text-neutral-500">{r.added_directly ? 'Added by staff' : 'Application'}</div></div> },
        ]}
      />
    </>
  )
}
