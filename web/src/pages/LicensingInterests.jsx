import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate } from '../lib/format.js'

export default function LicensingInterests() {
  return (
    <GenericList
      title="Licensing interests"
      subtitle="Prospects interested in AICOUNTLY product licenses."
      resource="licensing-interests"
      columns={[
        { key: 'lead_id', header: 'Lead #' },
        { key: 'product_code', header: 'Product' },
        { key: 'plan_code', header: 'Plan' },
        { key: 'seats', header: 'Seats', align: 'right' },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'lead_id', label: 'Lead ID', type: 'number', required: true },
        { key: 'product_code', label: 'Product code', required: true },
        { key: 'plan_code', label: 'Plan code' },
        { key: 'seats', label: 'Seats', type: 'number' },
        { key: 'status', label: 'Status', type: 'select', default: 'new', options: [
          { value: 'new', label: 'new' }, { value: 'qualifying', label: 'qualifying' },
          { value: 'proposal', label: 'proposal' }, { value: 'won', label: 'won' }, { value: 'lost', label: 'lost' },
        ] },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
