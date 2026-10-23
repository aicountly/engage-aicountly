import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { useAuth } from '../../lib/auth.jsx'
import { TONE_CLASSES, formatDateIN, formatPaise, lastDayIN } from '../../lib/licensing.js'
import { COUPON_ADMIN, COUPON_STATUSES, COUPON_TONES, ruleText } from '../../lib/coupons.js'
import CouponForm from './CouponForm.jsx'

export function CouponStatusBadge({ status }) {
  return <span className={clsx('engage-pill', TONE_CLASSES[COUPON_TONES[status]] || TONE_CLASSES.neutral)}>{status ? status.charAt(0).toUpperCase() + status.slice(1) : ''}</span>
}

/** Sales → Coupons: every coupon with its status and usage. Write actions only for super_admin | sales_manager. */
export default function Coupons() {
  const nav = useNavigate()
  const { hasRole } = useAuth()
  const canEdit = hasRole(COUPON_ADMIN)
  const [f, setF] = useState({ status: '', code_kind: '', q: '' })
  const [creating, setCreating] = useState(false)
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
  const q = useQuery({ queryKey: ['coupons', params], queryFn: () => api.get('/v1/coupons', { params }).then((r) => r.data?.data?.items || []) })

  return (
    <>
      <PageHeader
        title="Coupons"
        subtitle="Discount coupons for licences. GST is charged on the price after discount; seller commission is on the net."
        actions={canEdit ? <button className="engage-btn-primary" onClick={() => setCreating(true)}>+ New coupon</button> : null}
      />
      <div className="flex flex-wrap gap-2 mb-3">
        <select className="engage-input w-40" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
          <option value="">All statuses</option>
          {COUPON_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <select className="engage-input w-44" value={f.code_kind} onChange={(e) => setF({ ...f, code_kind: e.target.value })}>
          <option value="">Shared and single-use</option><option value="shared">Shared</option><option value="single_use">Single-use</option>
        </select>
        <input className="engage-input w-64" placeholder="Search label, CPN-…, or a code" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
      </div>
      <DataTable
        loading={q.isLoading}
        rows={q.data || []}
        onRowClick={(r) => nav(`/coupons/${r.id}`)}
        emptyMessage={canEdit ? 'Create a coupon, add or generate its codes, and have another sales manager or an administrator activate it.' : 'No coupons yet.'}
        columns={[
          { key: 'coupon_ref', header: 'Ref', render: (r) => <span className="font-mono text-xs">{r.coupon_ref}</span> },
          { key: 'label', header: 'Coupon', render: (r) => <div><div className="font-medium">{r.label}</div><div className="text-xs text-neutral-500">{r.code_kind === 'shared' ? 'Shared code' : 'Single-use codes'}{r.seller ? ` · seller ${r.seller.seller_code}` : ''}{r.campaign_ref ? ` · ${r.campaign_ref}` : ''}</div></div> },
          { key: 'rule', header: 'Discount', render: (r) => ruleText(r, formatPaise) },
          { key: 'validity', header: 'Valid', render: (r) => <span className="text-xs">{r.valid_from ? formatDateIN(r.valid_from) : 'now'} → {r.valid_to ? lastDayIN(r.valid_to) : 'open'}</span> },
          { key: 'usage', header: 'Used / limit', render: (r) => <span className="text-xs">{r.stats.committed} used · {r.stats.reserved} held{r.max_redemptions_total ? ` / ${r.max_redemptions_total}` : ''}</span> },
          { key: 'codes', header: 'Codes', render: (r) => <span className="text-xs">{r.stats.codes_available} of {r.stats.codes} free</span> },
          { key: 'discount', header: 'Discount given', align: 'right', render: (r) => formatPaise(r.stats.discount_committed_paise) },
          { key: 'status', header: 'Status', render: (r) => <CouponStatusBadge status={r.status} /> },
        ]}
      />
      {creating ? <CouponForm open coupon={null} onClose={() => setCreating(false)} onSaved={(d) => { setCreating(false); nav(`/coupons/${d.id}`) }} /> : null}
    </>
  )
}
