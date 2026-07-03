import GenericList from '../components/GenericList.jsx'
import { formatDate } from '../lib/format.js'

export default function Products() {
  return (
    <GenericList
      title="Products"
      subtitle="AICOUNTLY product catalogue used across leads, proposals and pricing."
      resource="products"
      columns={[
        { key: 'code', header: 'Code' },
        { key: 'name', header: 'Name' },
        { key: 'family', header: 'Family' },
        { key: 'is_active', header: 'Active', render: (r) => r.is_active ? 'Yes' : 'No' },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'code', label: 'Code', required: true },
        { key: 'name', label: 'Name', required: true },
        { key: 'family', label: 'Family', placeholder: 'core, extended, internal' },
        { key: 'description', label: 'Description', type: 'textarea' },
        { key: 'is_active', label: 'Active?', type: 'boolean', default: true, checkboxLabel: 'Available to sell' },
      ]}
    />
  )
}
