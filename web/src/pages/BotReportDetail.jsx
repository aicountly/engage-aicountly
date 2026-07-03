import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import { ApprovalBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

function JsonBlock({ data }) {
  if (data == null || data === '') return <span className="text-neutral-400">—</span>
  return <pre className="text-xs bg-neutral-50 border border-neutral-200 rounded p-2 whitespace-pre-wrap break-words">{typeof data === 'string' ? data : JSON.stringify(data, null, 2)}</pre>
}

export default function BotReportDetail() {
  const { id } = useParams()
  const { data, isLoading } = useQuery({
    queryKey: ['bot-report', id],
    queryFn: () => api.get(`/v1/bot/reports/${id}`).then((r) => r.data?.data || r.data),
  })
  const r = data || {}

  return (
    <>
      <PageHeader
        title={`Bot report #${id}`}
        subtitle={`${titleCase(r.action_code || '')} · ${formatDate(r.created_at, { withTime: true })}`}
        actions={
          <>
            <Link to="/bot/reports" className="engage-btn-secondary">Back</Link>
            <ApprovalBadge status={r.approval_status || 'not_required'} />
          </>
        }
      />
      {isLoading ? <div>Loading…</div> : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Card title="What the bot understood"><Block value={r.understanding} /></Card>
          <Card title="Data accessed"><JsonBlock data={r.data_accessed} /></Card>
          <Card title="Recommendation"><Block value={r.recommendation} /></Card>
          <Card title="Action proposed"><Block value={r.action_proposed} /></Card>
          <Card title="Action taken"><Block value={r.action_taken} /></Card>
          <Card title="Next recommended action"><Block value={r.next_recommended_action} /></Card>
          <Card title="Message draft"><Block value={r.message_draft} /></Card>
          <Card title="Proposal draft"><JsonBlock data={r.proposal_draft} /></Card>
          <Card title="Evidence"><JsonBlock data={r.evidence} /></Card>
          <Card title="Error"><Block value={r.error_message} /></Card>
        </div>
      )}
    </>
  )
}

function Card({ title, children }) {
  return (
    <div className="engage-card">
      <div className="text-xs uppercase tracking-wide text-neutral-500 mb-1">{title}</div>
      {children}
    </div>
  )
}
function Block({ value }) {
  if (!value) return <span className="text-neutral-400">—</span>
  return <div className="text-sm text-neutral-800 whitespace-pre-wrap">{value}</div>
}
