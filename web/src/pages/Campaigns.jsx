import GenericList from '../components/GenericList.jsx'
import { formatDate } from '../lib/format.js'

export default function Campaigns() {
  return (
    <GenericList
      title="Campaigns"
      subtitle="Cross-portal marketing / outbound campaigns feeding leads into Engage."
      resource="campaigns"
      columns={[
        { key: 'source_portal', header: 'Portal' },
        { key: 'campaign_code', header: 'Code' },
        { key: 'name', header: 'Name' },
        { key: 'campaign_kind', header: 'Kind' },
        { key: 'lead_count', header: 'Leads', align: 'right' },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'source_portal', label: 'Source portal', required: true, default: 'reach.aicountly.org' },
        { key: 'campaign_code', label: 'Campaign code' },
        { key: 'name', label: 'Name', required: true },
        { key: 'campaign_kind', label: 'Kind', default: 'reach' },
        { key: 'description', label: 'Description', type: 'textarea' },
      ]}
    />
  )
}
