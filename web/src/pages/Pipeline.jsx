import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import KanbanBoard from '../components/KanbanBoard.jsx'

export default function Pipeline() {
  const qc = useQueryClient()
  const nav = useNavigate()

  const stagesQ = useQuery({
    queryKey: ['pipeline-stages'],
    queryFn: () => api.get('/v1/pipeline-stages').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const kanbanQ = useQuery({
    queryKey: ['leads-kanban'],
    queryFn: () => api.get('/v1/leads/kanban').then((r) => {
      const data = r.data?.data || r.data
      const rows = Array.isArray(data) ? data : (data?.rows || [])
      const byStage = {}
      rows.forEach((col) => { byStage[col.stage?.code] = col.leads || [] })
      return byStage
    }),
  })

  const move = useMutation({
    mutationFn: ({ id, stage }) => api.post(`/v1/leads/${id}/move-stage`, { stage }).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['leads-kanban'] }),
  })

  return (
    <>
      <PageHeader
        title="Sales pipeline"
        subtitle="Kanban view of all leads across the AICOUNTLY sales pipeline."
      />
      <KanbanBoard
        columns={stagesQ.data || []}
        leadsByStage={kanbanQ.data || {}}
        onCardClick={(l) => nav(`/leads/${l.id}`)}
        onMove={(l, stage) => move.mutate({ id: l.id, stage })}
      />
    </>
  )
}
