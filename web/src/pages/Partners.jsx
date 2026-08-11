import { Link } from 'react-router-dom'
import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate } from '../lib/format.js'

/**
 * Partner Master listing. Engage is the only place partners are created —
 * partner.aicountly.com has no signup and never writes to this table.
 */
export default function Partners() {
  return (
    <GenericList
      title="Partners"
      subtitle="Partner Master for partner.aicountly.com — create partners here and grant portal access."
      resource="partners"
      filters={[
        { key: 'q', label: 'Search', placeholder: 'Name, contact, email, phone, partner ID…' },
        {
          key: 'status',
          label: 'Status',
          type: 'select',
          options: [
            { value: 'active', label: 'Active' },
            { value: 'inactive', label: 'Inactive' },
          ],
        },
        {
          key: 'has_portal_access',
          label: 'Portal access',
          type: 'select',
          options: [
            { value: '1', label: 'Credentials set' },
            { value: '0', label: 'No credentials' },
          ],
        },
      ]}
      columns={[
        {
          key: 'partner_uid',
          header: 'Partner ID',
          render: (r) => (
            <span className="font-mono text-xs text-neutral-500" title={r.partner_uid}>
              {String(r.partner_uid || '').slice(0, 8)}
            </span>
          ),
        },
        {
          key: 'name',
          header: 'Partner name',
          render: (r) => (
            <Link className="font-medium text-aicountly-700 hover:underline" to={`/partners/${r.id}`}>
              {r.name}
            </Link>
          ),
        },
        { key: 'email', header: 'Email' },
        { key: 'phone', header: 'Phone', render: (r) => r.phone || '—' },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        {
          key: 'has_portal_access',
          header: 'Portal access',
          render: (r) =>
            r.has_portal_access ? (
              <span className="engage-pill bg-green-50 text-green-800 border-green-200">Enabled</span>
            ) : (
              <span className="engage-pill bg-neutral-50 text-neutral-600 border-neutral-200">Not set</span>
            ),
        },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
        { key: 'updated_at', header: 'Updated', render: (r) => formatDate(r.updated_at) },
      ]}
      formFields={[
        { key: 'name', label: 'Partner name', required: true },
        { key: 'contact_name', label: 'Contact person' },
        { key: 'email', label: 'Email', type: 'email', required: true, help: 'Used as the Partner Portal login.' },
        { key: 'phone', label: 'Phone' },
        {
          key: 'partner_type',
          label: 'Partner type',
          type: 'select',
          options: [
            { value: 'reseller', label: 'Reseller' },
            { value: 'referral', label: 'Referral' },
            { value: 'implementation', label: 'Implementation' },
            { value: 'technology', label: 'Technology' },
          ],
        },
        {
          key: 'status',
          label: 'Status',
          type: 'select',
          default: 'active',
          options: [
            { value: 'active', label: 'Active' },
            { value: 'inactive', label: 'Inactive — cannot sign in' },
          ],
        },
        { key: 'website', label: 'Website', type: 'url' },
        { key: 'country', label: 'Country' },
        { key: 'city', label: 'City' },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
      canEdit={false}
      emptyMessage="No partners yet. Use + New to add the first partner."
    />
  )
}
