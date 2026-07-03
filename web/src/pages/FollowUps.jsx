import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

export default function FollowUps() {
  return (
    <GenericList
      title="Follow-ups"
      subtitle="Every planned touchpoint (calls, emails, WhatsApp, demos). Bot fills these automatically."
      resource="follow-ups"
      filters={[
        { key: 'status', label: 'Status', type: 'select', options: [
          { value: 'planned', label: 'planned' }, { value: 'due', label: 'due' },
          { value: 'done', label: 'done' }, { value: 'cancelled', label: 'cancelled' },
        ] },
        { key: 'channel', label: 'Channel', type: 'select', options: [
          { value: 'call', label: 'call' }, { value: 'email', label: 'email' },
          { value: 'whatsapp', label: 'whatsapp' }, { value: 'demo', label: 'demo' }, { value: 'meeting', label: 'meeting' },
        ] },
      ]}
      columns={[
        { key: 'due_at', header: 'When', nowrap: true, render: (r) => formatDate(r.due_at, { withTime: true }) },
        { key: 'lead_id', header: 'Lead' },
        { key: 'channel', header: 'Channel', render: (r) => titleCase(r.channel) },
        { key: 'subject', header: 'Subject' },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
      ]}
      formFields={[
        { key: 'lead_id', label: 'Lead ID', type: 'number', required: true },
        { key: 'due_at', label: 'Due at', type: 'datetime-local', required: true },
        { key: 'channel', label: 'Channel', type: 'select', default: 'call', options: [
          { value: 'call', label: 'call' }, { value: 'email', label: 'email' },
          { value: 'whatsapp', label: 'whatsapp' }, { value: 'demo', label: 'demo' }, { value: 'meeting', label: 'meeting' },
        ] },
        { key: 'subject', label: 'Subject' },
        { key: 'notes', label: 'Notes', type: 'textarea' },
      ]}
    />
  )
}
