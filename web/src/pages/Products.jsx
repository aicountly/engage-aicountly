import GenericList from '../components/GenericList.jsx'
import { useAuth } from '../lib/auth.jsx'
import { LICENSING_ADMIN } from '../lib/licensing.js'
import { formatDate } from '../lib/format.js'

export default function Products() {
  // The API lets only super_admin / sales_manager write the catalogue (other roles get 403).
  const { hasRole } = useAuth()
  const canWrite = hasRole(LICENSING_ADMIN)
  return (
    <GenericList
      canCreate={canWrite}
      canEdit={canWrite}
      title="Products"
      subtitle="Aicountly product catalogue used across leads, proposals and pricing."
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
