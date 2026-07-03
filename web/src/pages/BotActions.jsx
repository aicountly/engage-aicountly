import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'

export default function BotActions() {
  const { data, isLoading } = useQuery({
    queryKey: ['bot-actions'],
    queryFn: () => api.get('/v1/bot/actions').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  return (
    <>
      <PageHeader
        title="Bot capabilities"
        subtitle="The 14 sales-bot capabilities Engage supports. Enable them for Auto Mode in Bot settings."
      />
      <DataTable
        loading={isLoading}
        columns={[
          { key: 'code', header: 'Code' },
          { key: 'name', header: 'Name' },
          { key: 'description', header: 'Description' },
          { key: 'risk_level', header: 'Risk' },
          { key: 'requires_approval', header: 'Approval?', render: (r) => r.requires_approval ? 'Yes' : 'No' },
        ]}
        rows={data || []}
      />
    </>
  )
}
