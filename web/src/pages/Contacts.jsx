import GenericList from '../components/GenericList.jsx'

export default function Contacts() {
  return (
    <GenericList
      title="Contacts"
      subtitle="People associated with accounts and leads."
      resource="contacts"
      filters={[{ key: 'q', label: 'Search', placeholder: 'Name, email…' }]}
      columns={[
        { key: 'name', header: 'Name' },
        { key: 'title', header: 'Title' },
        { key: 'email', header: 'Email' },
        { key: 'mobile', header: 'Mobile' },
        { key: 'account_id', header: 'Account', render: (r) => r.account_name || (r.account_id ? `#${r.account_id}` : '—') },
      ]}
      formFields={[
        { key: 'name', label: 'Name', required: true },
        { key: 'title', label: 'Title' },
        { key: 'email', label: 'Email', type: 'email' },
        { key: 'mobile', label: 'Mobile' },
        { key: 'whatsapp', label: 'WhatsApp' },
        { key: 'account_id', label: 'Account ID', type: 'number' },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
