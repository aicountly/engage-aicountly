import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate } from '../lib/format.js'

export default function SubscriptionInquiries() {
  return (
    <GenericList
      title="Subscription inquiries"
      subtitle="Buyers asking about ongoing AICOUNTLY subscriptions."
      resource="subscription-inquiries"
      columns={[
        { key: 'lead_id', header: 'Lead #' },
        { key: 'product_code', header: 'Product' },
        { key: 'plan_code', header: 'Plan' },
        { key: 'expected_users', header: 'Users', align: 'right' },
        { key: 'expected_companies', header: 'Companies', align: 'right' },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'lead_id', label: 'Lead ID', type: 'number', required: true },
        { key: 'product_code', label: 'Product code' },
        { key: 'plan_code', label: 'Plan code' },
        { key: 'expected_users', label: 'Expected users', type: 'number' },
        { key: 'expected_companies', label: 'Expected companies', type: 'number' },
        { key: 'status', label: 'Status', type: 'select', default: 'new', options: [
          { value: 'new', label: 'new' }, { value: 'nurture', label: 'nurture' },
          { value: 'quoted', label: 'quoted' }, { value: 'won', label: 'won' }, { value: 'lost', label: 'lost' },
        ] },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
