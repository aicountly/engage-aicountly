import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import { formatDate } from '../lib/format.js'

function Row({ label, ok, detail }) {
  return (
    <div className="flex items-center justify-between border-b border-neutral-100 py-2">
      <div className="text-sm text-neutral-800">{label}</div>
      <div className="flex items-center gap-2">
        {detail ? <span className="text-xs text-neutral-500">{detail}</span> : null}
        <span className={`engage-pill ${ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>
          {ok ? 'OK' : 'Fail'}
        </span>
      </div>
    </div>
  )
}

export default function Health() {
  const { data, isLoading } = useQuery({
    queryKey: ['api-health'],
    queryFn: () => api.get('/health').then((r) => r.data?.data || r.data),
    refetchInterval: 30_000,
  })

  return (
    <>
      <PageHeader
        title="API health"
        subtitle="Live snapshot of database, JWT config, integrations and time."
      />
      <div className="engage-card">
        {isLoading ? <div>Loading…</div> : (
          <>
            <Row label="Overall" ok={data?.ok !== false} detail={data?.status} />
            {Object.entries(data?.checks || {}).map(([k, v]) => (
              <Row key={k} label={k} ok={v === 'ok' || v?.ok === true} detail={typeof v === 'string' ? (v === 'ok' ? '' : v) : v?.detail} />
            ))}
            <div className="mt-3 text-xs text-neutral-500">
              Server time: {formatDate(data?.time || data?.timestamp, { withTime: true })}
            </div>
          </>
        )}
      </div>
    </>
  )
}
