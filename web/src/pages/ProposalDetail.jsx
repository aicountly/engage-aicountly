import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import DataTable from '../components/DataTable.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, formatMoney } from '../lib/format.js'

export default function ProposalDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [line, setLine] = useState({ description: '', quantity: 1, unit_price: 0 })
  const [err, setErr] = useState(null)

  const propQ = useQuery({
    queryKey: ['proposal', id],
    queryFn: () => api.get(`/v1/proposals/${id}`).then((r) => r.data?.data || r.data),
  })
  const linesQ = useQuery({
    queryKey: ['proposal-lines', id],
    queryFn: () => api.get(`/v1/proposals/${id}/lines`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const addLine = useMutation({
    mutationFn: (payload) => api.post(`/v1/proposals/${id}/lines`, payload).then((r) => r.data),
    onSuccess: () => { setLine({ description: '', quantity: 1, unit_price: 0 }); qc.invalidateQueries({ queryKey: ['proposal-lines', id] }); qc.invalidateQueries({ queryKey: ['proposal', id] }) },
    onError: (e) => setErr(apiError(e)),
  })
  const delLine = useMutation({
    mutationFn: (lineId) => api.delete(`/v1/proposals/${id}/lines/${lineId}`).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['proposal-lines', id] }); qc.invalidateQueries({ queryKey: ['proposal', id] }) },
    onError: (e) => setErr(apiError(e)),
  })

  const p = propQ.data?.proposal || propQ.data || {}
  const lines = linesQ.data || []

  return (
    <>
      <PageHeader
        title={`Proposal ${p.code || `#${id}`}`}
        subtitle={p.title || '—'}
        actions={
          <>
            <Link to={`/leads/${p.lead_id}`} className="engage-btn-secondary">View lead</Link>
            <Link to="/proposals" className="engage-btn-secondary">Back</Link>
            <StatusBadge status={p.status} />
          </>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card">
          <div className="text-xs uppercase tracking-wide text-neutral-500">Totals</div>
          <div className="text-2xl font-semibold text-neutral-900 mt-1">{formatMoney(p.net_amount ?? p.total_amount, p.currency)}</div>
          <div className="mt-2 space-y-1 text-sm text-neutral-700">
            <div>Subtotal: <span className="font-medium">{formatMoney(p.subtotal ?? p.total_amount, p.currency)}</span></div>
            <div>Discount: <span className="font-medium">{formatMoney(p.discount_amount, p.currency)}</span></div>
            <div>Tax: <span className="font-medium">{formatMoney(p.tax_amount, p.currency)}</span></div>
          </div>
          <div className="mt-3 text-xs text-neutral-500">Valid until {formatDate(p.valid_until)}</div>
        </div>

        <div className="engage-card lg:col-span-2">
          <div className="text-sm font-semibold mb-2">Proposal lines</div>
          <DataTable
            columns={[
              { key: 'description', header: 'Description' },
              { key: 'quantity', header: 'Qty', align: 'right' },
              { key: 'unit_price', header: 'Unit', align: 'right', render: (r) => formatMoney(r.unit_price, p.currency) },
              { key: 'line_total', header: 'Total', align: 'right', render: (r) => formatMoney(r.line_total ?? (Number(r.unit_price || 0) * Number(r.quantity || 0)), p.currency) },
              { key: 'act', header: '', align: 'right', render: (r) => <button className="engage-btn-danger text-xs" onClick={() => delLine.mutate(r.id)}>Remove</button> },
            ]}
            rows={lines}
            emptyMessage="No lines yet."
          />

          <form
            className="mt-3 grid grid-cols-4 gap-2"
            onSubmit={(e) => { e.preventDefault(); setErr(null); addLine.mutate(line) }}
          >
            <input className="engage-input col-span-2" placeholder="Description" value={line.description} onChange={(e) => setLine({ ...line, description: e.target.value })} required />
            <input className="engage-input" type="number" step="1" min="1" value={line.quantity} onChange={(e) => setLine({ ...line, quantity: Number(e.target.value) })} required />
            <input className="engage-input" type="number" step="0.01" value={line.unit_price} onChange={(e) => setLine({ ...line, unit_price: Number(e.target.value) })} required />
            {err ? <div className="col-span-4 text-sm text-red-700">{err}</div> : null}
            <button className="engage-btn-primary col-span-4" disabled={addLine.isPending}>Add line</button>
          </form>
        </div>
      </div>
    </>
  )
}
