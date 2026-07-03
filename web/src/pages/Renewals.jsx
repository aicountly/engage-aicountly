import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, formatMoney } from '../lib/format.js'

export default function Renewals() {
  return (
    <GenericList
      title="Renewals"
      subtitle="Upcoming renewals; the bot prepares nudges via bot queue."
      resource="renewals"
      filters={[
        { key: 'status', label: 'Status', type: 'select', options: [
          { value: 'upcoming', label: 'upcoming' }, { value: 'due', label: 'due' },
          { value: 'renewed', label: 'renewed' }, { value: 'lost', label: 'lost' },
        ] },
        { key: 'due_within_days', label: 'Due within (days)', type: 'number' },
      ]}
      columns={[
        { key: 'account_id', header: 'Account' },
        { key: 'product_code', header: 'Product' },
        { key: 'plan_code', header: 'Plan' },
        { key: 'renewal_date', header: 'Renews on', render: (r) => formatDate(r.renewal_date) },
        { key: 'expected_amount', header: 'Value', align: 'right', render: (r) => formatMoney(r.expected_amount, r.currency) },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
      ]}
      formFields={[
        { key: 'lead_id', label: 'Lead ID', type: 'number' },
        { key: 'account_id', label: 'Account ID', type: 'number' },
        { key: 'product_code', label: 'Product code' },
        { key: 'plan_code', label: 'Plan code' },
        { key: 'renewal_date', label: 'Renewal date', type: 'date', required: true },
        { key: 'currency', label: 'Currency', default: 'USD' },
        { key: 'expected_amount', label: 'Expected value', type: 'number' },
        { key: 'status', label: 'Status', type: 'select', default: 'upcoming', options: [
          { value: 'upcoming', label: 'upcoming' }, { value: 'due', label: 'due' },
          { value: 'renewed', label: 'renewed' }, { value: 'lost', label: 'lost' },
        ] },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
