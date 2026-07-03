import { Link } from 'react-router-dom'
import GenericList from '../components/GenericList.jsx'
import { formatDate } from '../lib/format.js'

export default function Accounts() {
  return (
    <GenericList
      title="Accounts"
      subtitle="Companies/prospects associated with leads and proposals."
      resource="accounts"
      filters={[{ key: 'q', label: 'Search', placeholder: 'Name, website, industry…' }]}
      columns={[
        { key: 'name', header: 'Name', render: (r) => <Link className="font-medium text-aicountly-700 hover:underline" to={`/accounts/${r.id}`}>{r.name}</Link> },
        { key: 'industry', header: 'Industry' },
        { key: 'website', header: 'Website', render: (r) => r.website ? <a target="_blank" rel="noreferrer" className="text-aicountly-700 hover:underline" href={r.website}>{r.website}</a> : '—' },
        { key: 'country', header: 'Country' },
        { key: 'created_at', header: 'Created', render: (r) => formatDate(r.created_at) },
      ]}
      formFields={[
        { key: 'name', label: 'Name', required: true },
        { key: 'industry', label: 'Industry' },
        { key: 'website', label: 'Website', type: 'url' },
        { key: 'country', label: 'Country' },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
