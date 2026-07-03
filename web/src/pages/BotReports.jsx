import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import FilterBar from '../components/FilterBar.jsx'
import { ApprovalBadge } from '../components/Badges.jsx'
import { formatDate, titleCase, truncate } from '../lib/format.js'

export default function BotReports() {
  const [f, setF] = useState({})
  const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))).toString()

  const listQ = useQuery({
    queryKey: ['bot-reports', q],
    queryFn: () => api.get(`/v1/bot/reports?per_page=100&${q}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
    keepPreviousData: true,
  })

  return (
    <>
      <PageHeader
        title="Bot reports"
        subtitle="Every Sales Bot action leaves a full report here (understanding, data accessed, recommendation, action, next action)."
      />

      <FilterBar
        filters={[
          { key: 'action_code', label: 'Action', placeholder: 'e.g. score_lead' },
          { key: 'subject_kind', label: 'Subject kind', type: 'select', options: [
            { value: 'lead', label: 'lead' }, { value: 'account', label: 'account' },
            { value: 'proposal', label: 'proposal' }, { value: 'renewal', label: 'renewal' },
          ] },
          { key: 'subject_id', label: 'Subject ID', type: 'number' },
          { key: 'approval_status', label: 'Approval', type: 'select', options: [
            { value: 'not_required', label: 'not_required' }, { value: 'auto_approved', label: 'auto_approved' },
            { value: 'pending', label: 'pending' }, { value: 'approved', label: 'approved' },
            { value: 'rejected', label: 'rejected' }, { value: 'executed', label: 'executed' },
          ] },
        ]}
        value={f}
        onChange={setF}
      />

      <DataTable
        loading={listQ.isLoading}
        columns={[
          { key: 'id', header: '#', nowrap: true, render: (r) => <Link className="font-medium text-aicountly-700 hover:underline" to={`/bot/reports/${r.id}`}>#{r.id}</Link> },
          { key: 'action_code', header: 'Action', render: (r) => titleCase(r.action_code) },
          { key: 'subject_kind', header: 'Subject', render: (r) => r.subject_kind ? `${r.subject_kind}${r.subject_id ? ` #${r.subject_id}` : ''}` : '—' },
          { key: 'recommendation', header: 'Recommendation', render: (r) => truncate(r.recommendation, 90) || '—' },
          { key: 'approval_status', header: 'Approval', render: (r) => <ApprovalBadge status={r.approval_status || 'not_required'} /> },
          { key: 'created_at', header: 'When', render: (r) => formatDate(r.created_at, { withTime: true }) },
        ]}
        rows={listQ.data || []}
        emptyMessage="No bot reports yet."
      />
    </>
  )
}
