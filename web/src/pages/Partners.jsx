import { useState } from 'react'
import { Link } from 'react-router-dom'
import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate } from '../lib/format.js'

/**
 * Partner Master listing. Engage is the only place partners are created —
 * partner.aicountly.com has no signup and never writes to this table.
 */
export default function Partners() {
  // Set once, when Engage generated the password for a partner being created.
  const [issued, setIssued] = useState(null)

  return (
    <>
      {issued ? (
        <div className="mb-4 rounded-md bg-amber-50 border border-amber-300 text-amber-900 text-sm px-3 py-3">
          <div className="font-semibold">
            Partner created — password for {issued.name}, shown once
          </div>
          <div className="mt-1 font-mono text-base">{issued.password}</div>
          <div className="mt-1 text-xs">
            Send it to {issued.email} over a secure channel. Engage stores only a hash and cannot show it again —
            if it is lost, reset the password from the partner&rsquo;s page.
          </div>
          <button className="engage-btn-secondary mt-2" onClick={() => setIssued(null)}>Dismiss</button>
        </div>
      ) : null}

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
        {
          key: 'generate',
          label: 'Partner Portal password',
          type: 'boolean',
          default: true,
          checkboxLabel: 'Generate a strong password now (shown once after saving)',
        },
        {
          key: 'password',
          label: 'Or set the password yourself',
          type: 'password',
          help: 'Only used when the box above is unticked. At least 10 characters, including a letter and a number. Leave both empty to grant portal access later from the partner’s page.',
        },
      ]}
      canEdit={false}
      emptyMessage="No partners yet. Use + New to add the first partner."
      onCreated={(partner) => {
        if (partner?.generated_password) {
          setIssued({
            name: partner.name,
            email: partner.email,
            password: partner.generated_password,
          })
        }
      }}
      />
    </>
  )
}
