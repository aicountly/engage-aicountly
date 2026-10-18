import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'

export default function SellerKycQueue() {
  const [status, setStatus] = useState('kyc_submitted')
  const q = useQuery({ queryKey: ['seller-kyc', status], queryFn: () => api.get(`/v1/seller-kyc?status=${status}&limit=200`).then((r) => r.data?.data) })

  return (
    <>
      <PageHeader title="KYC review" subtitle="Verify seller KYC against the uploaded documents. Required before any payout." />
      <div className="mb-3 flex gap-2">
        {['kyc_submitted', 'kyc_rejected', 'kyc_verified', 'draft'].map((s) => (
          <button key={s} className={s === status ? 'engage-btn-primary' : 'engage-btn-secondary'} onClick={() => setStatus(s)}>{titleCase(s)}</button>
        ))}
      </div>
      <DataTable
        keyField="seller_id"
        loading={q.isLoading}
        rows={q.data?.items || []}
        emptyMessage="Nothing waiting."
        columns={[
          { key: 'seller_code', header: 'Seller', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller_id}`}>{r.seller_code} · {r.name}</Link> },
          { key: 'legal_name', header: 'Legal name' },
          { key: 'entity_type', header: 'Entity', render: (r) => titleCase(r.entity_type || '') },
          { key: 'status', header: 'KYC', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'submission_count', header: 'Submissions' },
          { key: 'submitted_at', header: 'Submitted', render: (r) => formatDate(r.submitted_at, { withTime: true }) },
        ]}
      />
    </>
  )
}
