import { Link } from 'react-router-dom'
import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, formatMoney } from '../lib/format.js'

export default function Proposals() {
  return (
    <GenericList
      title="Proposals"
      subtitle="Formal sales proposals with line items and totals."
      resource="proposals"
      filters={[
        { key: 'status', label: 'Status', type: 'select', options: [
          { value: 'draft', label: 'Draft' }, { value: 'sent', label: 'Sent' },
          { value: 'negotiation', label: 'Negotiation' }, { value: 'won', label: 'Won' },
          { value: 'lost', label: 'Lost' }, { value: 'expired', label: 'Expired' },
        ] },
      ]}
      columns={[
        { key: 'code', header: 'Code', render: (r) => <Link className="font-medium text-aicountly-700 hover:underline" to={`/proposals/${r.id}`}>{r.code || `#${r.id}`}</Link> },
        { key: 'title', header: 'Title' },
        { key: 'lead_id', header: 'Lead' },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        { key: 'net_amount', header: 'Net', align: 'right', render: (r) => formatMoney(r.net_amount ?? r.total_amount, r.currency) },
        { key: 'valid_until', header: 'Valid until', render: (r) => formatDate(r.valid_until) },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'lead_id', label: 'Lead ID', type: 'number', required: true },
        { key: 'title', label: 'Title', required: true },
        { key: 'currency', label: 'Currency', default: 'USD' },
        { key: 'valid_until', label: 'Valid until', type: 'date' },
        { key: 'summary', label: 'Summary', type: 'textarea' },
      ]}
    />
  )
}
