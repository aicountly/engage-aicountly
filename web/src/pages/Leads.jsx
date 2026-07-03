import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import FilterBar from '../components/FilterBar.jsx'
import DataTable from '../components/DataTable.jsx'
import { PriorityBadge, ScoreBadge, SourceBadge, StageBadge } from '../components/Badges.jsx'
import { formatDate, truncate } from '../lib/format.js'

const SOURCE_TYPES = [
  { value: 'reach_campaign', label: 'Reach campaign' },
  { value: 'direct', label: 'Direct' },
  { value: 'referral', label: 'Referral' },
  { value: 'website', label: 'Website' },
  { value: 'manual', label: 'Manual' },
  { value: 'import', label: 'Import' },
  { value: 'webinar', label: 'Webinar' },
  { value: 'social', label: 'Social' },
  { value: 'other', label: 'Other' },
]
const PRIORITIES = ['low', 'normal', 'high', 'urgent'].map((v) => ({ value: v, label: v }))

export default function Leads() {
  const [params, setParams] = useSearchParams()
  const [filters, setFilters] = useState(() => Object.fromEntries(params))

  const stagesQ = useQuery({
    queryKey: ['pipeline-stages'],
    queryFn: () => api.get('/v1/pipeline-stages').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const productsQ = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get('/v1/products').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const q = useMemo(() => {
    const cleaned = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null))
    return new URLSearchParams(cleaned).toString()
  }, [filters])

  const { data, isLoading } = useQuery({
    queryKey: ['leads', q],
    queryFn: () => api.get(`/v1/leads?${q}`).then((r) => r.data?.data),
    keepPreviousData: true,
  })

  const rows = data?.rows || []
  const paging = data?.paging

  function applyFilters(next) {
    setFilters(next)
    const cleaned = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v != null))
    setParams(cleaned)
  }

  const columns = [
    { key: 'lead_code', header: 'Code', nowrap: true, render: (r) => (
        <Link className="font-medium text-aicountly-700 hover:underline" to={`/leads/${r.id}`}>{r.lead_code || `#${r.id}`}</Link>
      ) },
    { key: 'name', header: 'Lead', render: (r) => (
        <div>
          <div className="font-medium text-neutral-900">{r.name}</div>
          <div className="text-xs text-neutral-500">{r.organization || r.email || '—'}</div>
        </div>
      ) },
    { key: 'stage', header: 'Stage', render: (r) => <StageBadge stage={r.stage} /> },
    { key: 'lead_score', header: 'Score', render: (r) => <ScoreBadge score={r.lead_score} bucket={r.score_bucket} /> },
    { key: 'priority', header: 'Priority', render: (r) => <PriorityBadge priority={r.priority} /> },
    { key: 'source_type', header: 'Source', render: (r) => <SourceBadge source={r.source_type} /> },
    { key: 'interested_product', header: 'Product', nowrap: true, render: (r) => r.interested_product || '—' },
    { key: 'next_follow_up_date', header: 'Next follow-up', nowrap: true, render: (r) => formatDate(r.next_follow_up_date) },
    { key: 'bot_summary', header: 'Bot summary', render: (r) => truncate(r.bot_summary || '', 60) || <span className="text-neutral-400">—</span> },
  ]

  return (
    <>
      <PageHeader
        title="Leads"
        subtitle="All sales leads across sources and campaigns."
        actions={<Link to="/pipeline" className="engage-btn-secondary">Open pipeline</Link>}
      />

      <FilterBar
        value={filters}
        onChange={applyFilters}
        filters={[
          { key: 'q', label: 'Search', placeholder: 'Name, org, email, code…' },
          { key: 'stage', label: 'Stage', type: 'select', options: (stagesQ.data || []).map((s) => ({ value: s.code, label: s.name })) },
          { key: 'source_type', label: 'Source type', type: 'select', options: SOURCE_TYPES },
          { key: 'priority', label: 'Priority', type: 'select', options: PRIORITIES },
          { key: 'interested_product', label: 'Product', type: 'select', options: (productsQ.data || []).map((p) => ({ value: p.code, label: p.name })) },
          { key: 'min_score', label: 'Min score', type: 'number' },
          { key: 'due_before', label: 'Follow-up due before', type: 'date' },
        ]}
      />

      <DataTable
        columns={columns}
        rows={rows}
        loading={isLoading}
        emptyTitle="No leads yet"
        emptyMessage="Reach campaigns and manual entries will appear here."
      />

      {paging ? (
        <div className="mt-3 text-xs text-neutral-500">
          Showing {rows.length} of {paging.total ?? rows.length}
        </div>
      ) : null}
    </>
  )
}
