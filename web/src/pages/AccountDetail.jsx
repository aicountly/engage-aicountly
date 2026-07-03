import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import { StageBadge, ScoreBadge } from '../components/Badges.jsx'
import { formatDate } from '../lib/format.js'

export default function AccountDetail() {
  const { id } = useParams()
  const { data, isLoading } = useQuery({
    queryKey: ['account', id],
    queryFn: () => api.get(`/v1/accounts/${id}`).then((r) => r.data?.data || r.data),
  })
  const a = data?.account || data || {}
  const contacts = data?.contacts || []
  const leads = data?.leads || []

  return (
    <>
      <PageHeader
        title={a.name || `Account #${id}`}
        subtitle={[a.industry, a.country].filter(Boolean).join(' · ') || '—'}
        actions={<Link to="/accounts" className="engage-btn-secondary">Back</Link>}
      />
      {isLoading ? <div>Loading…</div> : (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="engage-card lg:col-span-1">
            <div className="text-xs text-neutral-500">Website</div>
            <div className="text-sm font-medium">{a.website ? <a target="_blank" rel="noreferrer" className="text-aicountly-700 hover:underline" href={a.website}>{a.website}</a> : '—'}</div>
            <div className="mt-3 text-xs text-neutral-500">Created</div>
            <div className="text-sm font-medium">{formatDate(a.created_at, { withTime: true })}</div>
            {a.notes ? (
              <>
                <div className="mt-3 text-xs uppercase tracking-wide text-neutral-500">Notes</div>
                <div className="text-sm text-neutral-800 whitespace-pre-wrap">{a.notes}</div>
              </>
            ) : null}
          </div>
          <div className="lg:col-span-2 space-y-4">
            <div>
              <div className="text-sm font-semibold mb-2">Contacts</div>
              <DataTable
                columns={[
                  { key: 'name', header: 'Name' },
                  { key: 'title', header: 'Title' },
                  { key: 'email', header: 'Email' },
                  { key: 'mobile', header: 'Mobile' },
                ]}
                rows={contacts}
                emptyMessage="No contacts linked."
              />
            </div>
            <div>
              <div className="text-sm font-semibold mb-2">Leads</div>
              <DataTable
                columns={[
                  { key: 'name', header: 'Name', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/leads/${r.id}`}>{r.name}</Link> },
                  { key: 'stage', header: 'Stage', render: (r) => <StageBadge stage={r.stage} /> },
                  { key: 'lead_score', header: 'Score', render: (r) => <ScoreBadge score={r.lead_score} bucket={r.score_bucket} /> },
                  { key: 'next_follow_up_date', header: 'Next follow-up', render: (r) => formatDate(r.next_follow_up_date) },
                ]}
                rows={leads}
                emptyMessage="No leads linked."
              />
            </div>
          </div>
        </div>
      )}
    </>
  )
}
