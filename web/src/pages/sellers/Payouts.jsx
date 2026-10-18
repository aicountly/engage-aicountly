import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import DataTable from '../../components/DataTable.jsx'
import Drawer from '../../components/Drawer.jsx'
import { StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { PAYOUT_ADMIN, inr } from '../../lib/sellerRoles.js'

const TABS = [
  ['runs', 'Runs'],
  ['preview', 'Next run preview'],
  ['payouts', 'Payouts per seller'],
]

const PAYOUT_STATUSES = ['', 'queued', 'draft_sent', 'on_hold', 'approved', 'paid', 'failed', 'reversed', 'cancelled']

function Notice({ tone = 'amber', children }) {
  const cls = tone === 'green' ? 'bg-green-50 border-green-200 text-green-900'
    : tone === 'red' ? 'bg-red-50 border-red-200 text-red-800'
    : 'bg-amber-50 border-amber-200 text-amber-900'
  return <div className={`mb-4 rounded-md text-sm px-3 py-2 border ${cls}`}>{children}</div>
}

function Field({ label, children }) {
  return (
    <div>
      <div className="text-xs text-neutral-500">{label}</div>
      <div className="text-sm text-neutral-900">{children ?? '—'}</div>
    </div>
  )
}

/** Sellers → Payouts: weekly runs (drafts sent to finance.aicountly.org), the next run's preview, payouts per seller. */
export default function Payouts() {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const isAdmin = hasRole(PAYOUT_ADMIN)
  const [params] = useSearchParams()
  const sellerId = params.get('seller_id') || ''
  const [tab, setTab] = useState(sellerId ? 'payouts' : 'runs')
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [runId, setRunId] = useState(null)
  const [payoutId, setPayoutId] = useState(null)
  const [status, setStatus] = useState('')
  const [week, setWeek] = useState('')

  const runs = useQuery({ queryKey: ['payout-runs'], queryFn: () => api.get('/v1/payout-runs?limit=100').then((r) => r.data?.data) })
  const preview = useQuery({
    queryKey: ['payout-preview'],
    queryFn: () => api.get('/v1/payout-runs/preview').then((r) => r.data?.data),
    enabled: tab === 'preview',
  })
  const payoutsQs = new URLSearchParams(Object.fromEntries(Object.entries({ status, week, seller_id: sellerId }).filter(([, v]) => v))).toString()
  const payouts = useQuery({
    queryKey: ['seller-payouts', payoutsQs],
    queryFn: () => api.get(`/v1/seller-payouts?limit=200&${payoutsQs}`).then((r) => r.data?.data),
    enabled: tab === 'payouts',
  })
  const run = useQuery({
    queryKey: ['payout-run', runId],
    queryFn: () => api.get(`/v1/payout-runs/${runId}`).then((r) => r.data?.data),
    enabled: !!runId,
  })
  const payout = useQuery({
    queryKey: ['seller-payout', payoutId],
    queryFn: () => api.get(`/v1/seller-payouts/${payoutId}`).then((r) => r.data?.data),
    enabled: !!payoutId,
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['payout-runs'] })
    qc.invalidateQueries({ queryKey: ['payout-run'] })
    qc.invalidateQueries({ queryKey: ['payout-preview'] })
    qc.invalidateQueries({ queryKey: ['seller-payouts'] })
  }
  const done = (msg) => { setError(null); setInfo(msg); refresh() }
  const fail = (e) => { setInfo(null); setError(apiError(e)) }

  const manualRun = useMutation({
    mutationFn: (w) => api.post('/v1/payout-runs', w ? { week: w } : {}).then((r) => r.data?.data),
    onSuccess: (d) => done(d?.result === 'exists'
      ? `Week ${d.run.week} already has a run (${titleCase(d.run.status)}); nothing was rebuilt.`
      : `Run ${d.run.week}: ${titleCase(d.run.status)} — ${d.run.payouts_count} payout(s), ${inr(d.run.gross_amount)} gross.`),
    onError: fail,
  })
  const resend = useMutation({
    mutationFn: (id) => api.post(`/v1/payout-runs/${id}/resend`).then((r) => r.data?.data),
    onSuccess: (d) => done(`Re-send: ${d.delivery === 'delivered' ? 'Finance accepted the batch.' : `not accepted (${d.delivery}). ${d.run?.delivery?.last_error || ''}`}`),
    onError: fail,
  })
  const cancel = useMutation({
    mutationFn: ({ id, reason }) => api.post(`/v1/payout-runs/${id}/cancel`, { reason }).then((r) => r.data?.data),
    onSuccess: (d) => done(`Batch cancelled; ${d.entries_returned} commission entr${d.entries_returned === 1 ? 'y' : 'ies'} back to approved.`),
    onError: fail,
  })

  const fin = runs.data?.finance
  const sched = runs.data?.schedule

  const startRun = () => {
    const w = window.prompt(`Run which ISO week? (default ${sched?.last_completed_week || 'the last week that ended'})`, sched?.last_completed_week || '')
    if (w === null) return
    if (window.confirm(`Build the payout run for ${w || 'the last completed week'} and send it to Finance as a draft batch?`)) manualRun.mutate(w.trim())
  }

  return (
    <>
      <PageHeader
        title="Payouts"
        subtitle="Weekly seller payouts are compiled as drafts every Monday 10:00 IST and sent to finance.aicountly.org, where a person reviews and finalises them. Engage marks a payout paid only when Finance reports it paid with a UTR."
        actions={isAdmin ? <button className="engage-btn-primary" disabled={manualRun.isPending} onClick={startRun}>Run a week now</button> : null}
      />
      {error ? <Notice tone="red">{error}</Notice> : null}
      {info ? <Notice tone="green">{info}</Notice> : null}
      {fin ? (
        <Notice tone={fin.configured && fin.inbound_key === 'ok' ? 'green' : 'amber'}>
          Finance outbound: {fin.status} · inbound status key: {fin.inbound_key} · batches pending {fin.outbox?.pending ?? 0}, delivered {fin.outbox?.delivered ?? 0},
          rejected {fin.outbox?.rejected ?? 0}, dead {fin.outbox?.dead ?? 0}
          {sched ? <> · next run {formatDate(sched.next_run_date)} ({sched.at}) for {sched.next_run_week} · minimum payout {inr(runs.data?.min_payout)}</> : null}
        </Notice>
      ) : null}

      <div className="mb-3 flex gap-2">
        {TABS.map(([k, label]) => (
          <button key={k} className={k === tab ? 'engage-btn-primary' : 'engage-btn-secondary'} onClick={() => setTab(k)}>{label}</button>
        ))}
      </div>

      {tab === 'runs' ? (
        <DataTable
          loading={runs.isLoading}
          rows={runs.data?.items || []}
          onRowClick={(r) => setRunId(r.id)}
          emptyMessage="No payout runs yet. The first one runs on Monday 10:00 IST."
          columns={[
            { key: 'week', header: 'Week', render: (r) => <span className="font-mono text-xs">{r.week}</span> },
            { key: 'period', header: 'Period', render: (r) => `${formatDate(r.period_start)} – ${formatDate(r.period_end)}` },
            { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            { key: 'payouts_count', header: 'Payouts', align: 'right' },
            { key: 'gross_amount', header: 'Gross', align: 'right', render: (r) => inr(r.gross_amount) },
            { key: 'below', header: 'Rolled over', align: 'right', render: (r) => (r.below_minimum_count || r.ineligible_count ? `${inr(r.below_minimum_amount)} + ${inr(r.ineligible_amount)} held` : '—') },
            { key: 'delivery', header: 'Finance delivery', render: (r) => (r.delivery ? <span title={r.delivery.last_error || ''}><StatusBadge status={r.delivery.status} /> {r.delivery.attempts > 1 ? `(${r.delivery.attempts} tries)` : ''}</span> : '—') },
            { key: 'trigger', header: 'Trigger', render: (r) => titleCase(r.trigger) },
            { key: 'error', header: 'Error', render: (r) => <span className="text-xs text-red-700">{r.error || ''}</span> },
            { key: 'actions', header: '', render: (r) => (isAdmin && r.can_resend ? (
              <button className="engage-btn-secondary text-xs" onClick={(e) => { e.stopPropagation(); resend.mutate(r.id) }}>Re-send to Finance</button>
            ) : null) },
          ]}
        />
      ) : null}

      {tab === 'preview' ? (
        preview.isLoading ? <div className="text-sm text-neutral-500">Loading…</div> : preview.data ? (
          <>
            <Notice>
              Dry run for {preview.data.week} ({formatDate(preview.data.period_start)} – {formatDate(preview.data.period_end)}), scheduled {formatDate(preview.data.scheduled_run_at, { withTime: true })}:
              {' '}{preview.data.payouts_count} payout(s), {inr(preview.data.gross_amount)} gross; {inr(preview.data.held_amount)} held or below the minimum ({inr(preview.data.min_payout)}).
              {' '}{preview.data.note || ''} Nothing is reserved or sent by this preview.
            </Notice>
            <DataTable
              keyField="seller_id"
              rows={preview.data.sellers || []}
              emptyMessage="No approved commission is waiting for a payout."
              columns={[
                { key: 'seller_code', header: 'Seller', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller_id}`}>{r.seller_code}</Link> },
                { key: 'seller_name', header: 'Name' },
                { key: 'kyc_status', header: 'KYC', render: (r) => <StatusBadge status={r.kyc_status} /> },
                { key: 'entries_count', header: 'Entries', align: 'right' },
                { key: 'gross_amount', header: 'Gross', align: 'right', render: (r) => inr(r.gross_amount) },
                { key: 'included', header: 'In the run', render: (r) => (r.included ? <StatusBadge status="included" /> : <span className="text-xs text-amber-800">{r.skip_label}</span>) },
              ]}
            />
          </>
        ) : null
      ) : null}

      {tab === 'payouts' ? (
        <>
          <div className="mb-3 flex flex-wrap gap-2 items-center">
            {PAYOUT_STATUSES.map((s) => (
              <button key={s || 'all'} className={s === status ? 'engage-btn-primary' : 'engage-btn-secondary'} onClick={() => setStatus(s)}>{s ? titleCase(s) : 'All'}</button>
            ))}
            <input className="engage-input w-32" placeholder="2026-W42" value={week} onChange={(e) => setWeek(e.target.value.trim())} />
            {sellerId ? <Link className="text-xs text-aicountly-700 hover:underline" to="/seller-payouts">Show all sellers</Link> : null}
          </div>
          <DataTable
            loading={payouts.isLoading}
            rows={payouts.data?.items || []}
            onRowClick={(r) => setPayoutId(r.id)}
            emptyMessage="No payouts."
            columns={[
              { key: 'payout_ref', header: 'Payout', render: (r) => <span className="font-mono text-xs">{r.payout_ref}</span> },
              { key: 'seller_code', header: 'Seller', render: (r) => <Link className="text-aicountly-700 hover:underline" to={`/sellers/${r.seller_id}`} onClick={(e) => e.stopPropagation()}>{r.seller_code}</Link> },
              { key: 'week', header: 'Week' },
              { key: 'gross_amount', header: 'Gross', align: 'right', render: (r) => inr(r.gross_amount) },
              { key: 'tds_amount', header: 'TDS', align: 'right', render: (r) => (r.tds_amount === null ? 'Pending' : inr(r.tds_amount)) },
              { key: 'gst_amount', header: 'GST', align: 'right', render: (r) => (r.gst_amount === null ? 'Pending' : inr(r.gst_amount)) },
              { key: 'net_amount', header: 'Net', align: 'right', render: (r) => (r.net_amount === null ? 'Pending' : inr(r.net_amount)) },
              { key: 'status', header: 'Status (from Finance)', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'utr', header: 'UTR', render: (r) => <span className="font-mono text-xs">{r.utr || '—'}</span> },
            ]}
          />
        </>
      ) : null}

      <Drawer open={!!runId} title={run.data ? `Payout run ${run.data.week} · ${run.data.batch_ref}` : 'Payout run'} onClose={() => setRunId(null)} size="xl"
        footer={isAdmin && run.data?.can_resend ? (
          <>
            <button className="engage-btn-danger" onClick={() => { const reason = window.prompt('Cancel this batch (Finance never received it) — reason:'); if (reason) cancel.mutate({ id: run.data.id, reason }) }}>Cancel batch</button>
            <button className="engage-btn-primary" onClick={() => resend.mutate(run.data.id)}>Re-send to Finance</button>
          </>
        ) : null}>
        {run.data ? (
          <>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Status"><StatusBadge status={run.data.status} /></Field>
              <Field label="Period">{formatDate(run.data.period_start)} – {formatDate(run.data.period_end)}</Field>
              <Field label="Cutoff">{formatDate(run.data.cutoff_at, { withTime: true })}</Field>
              <Field label="Payouts / entries">{run.data.payouts_count} / {run.data.entries_count}</Field>
              <Field label="Gross">{inr(run.data.gross_amount)}</Field>
              <Field label="Minimum payout">{inr(run.data.min_payout)}</Field>
              <Field label="Below minimum (rolls over)">{run.data.below_minimum_count} · {inr(run.data.below_minimum_amount)}</Field>
              <Field label="Held (KYC / seller status)">{run.data.ineligible_count} · {inr(run.data.ineligible_amount)}</Field>
              <Field label="Finance batch id">{run.data.finance_batch_id}</Field>
              <Field label="Delivery">{run.data.delivery ? `${titleCase(run.data.delivery.status)} · ${run.data.delivery.attempts} attempt(s)` : '—'}</Field>
              <Field label="Last HTTP status">{run.data.delivery?.last_http_status}</Field>
              <Field label="Sent">{formatDate(run.data.sent_at, { withTime: true })}</Field>
            </div>
            {run.data.error ? <Notice tone="red">{run.data.error}</Notice> : null}
            <DataTable
              rows={run.data.payouts || []}
              onRowClick={(r) => setPayoutId(r.id)}
              emptyMessage="This run has no payouts."
              columns={[
                { key: 'payout_ref', header: 'Payout', render: (r) => <span className="font-mono text-xs">{r.payout_ref}</span> },
                { key: 'seller_code', header: 'Seller' },
                { key: 'gross_amount', header: 'Gross', align: 'right', render: (r) => inr(r.gross_amount) },
                { key: 'net_amount', header: 'Net', align: 'right', render: (r) => (r.net_amount === null ? 'Pending' : inr(r.net_amount)) },
                { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
                { key: 'utr', header: 'UTR', render: (r) => r.utr || '—' },
              ]}
            />
          </>
        ) : <div className="text-sm text-neutral-500">Loading…</div>}
      </Drawer>

      <Drawer open={!!payoutId} title={payout.data ? `Payout ${payout.data.payout_ref}` : 'Payout'} onClose={() => setPayoutId(null)} size="xl">
        {payout.data ? (
          <>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Seller"><Link className="text-aicountly-700 hover:underline" to={`/sellers/${payout.data.seller_id}`}>{payout.data.seller_code}</Link> {payout.data.seller_name}</Field>
              <Field label="Status (from Finance)"><StatusBadge status={payout.data.status} /></Field>
              <Field label="Period">{formatDate(payout.data.period_start)} – {formatDate(payout.data.period_end)}</Field>
              <Field label="Gross">{inr(payout.data.gross_amount)}</Field>
              <Field label={`TDS${payout.data.tds_section ? ` (${payout.data.tds_section})` : ''}`}>{payout.data.tds_amount === null ? 'Pending' : inr(payout.data.tds_amount)}</Field>
              <Field label="GST">{payout.data.gst_amount === null ? 'Pending' : inr(payout.data.gst_amount)}</Field>
              <Field label="Net">{payout.data.net_amount === null ? 'Pending' : inr(payout.data.net_amount)}</Field>
              <Field label="UTR">{payout.data.utr}</Field>
              <Field label="Paid at">{formatDate(payout.data.paid_at, { withTime: true })}</Field>
            </div>
            {payout.data.reason ? <Notice>{payout.data.reason}</Notice> : null}
            <h3 className="font-semibold text-sm">Commission entries</h3>
            <DataTable
              keyField="entry_id"
              rows={payout.data.entries || []}
              columns={[
                { key: 'id', header: 'Entry' },
                { key: 'lead_code', header: 'Lead' },
                { key: 'product', header: 'Product' },
                { key: 'amount', header: 'Amount', align: 'right', render: (r) => inr(r.amount) },
                { key: 'status', header: 'Entry status', render: (r) => <><StatusBadge status={r.status} />{r.in_this_payout ? '' : <span className="text-xs text-neutral-500"> (left this payout)</span>}</> },
              ]}
            />
            <h3 className="font-semibold text-sm">Timeline</h3>
            <ul className="text-sm space-y-1">
              {(payout.data.events || []).map((ev, i) => (
                <li key={i}>
                  <span className="text-neutral-500">{formatDate(ev.at, { withTime: true })}</span> · {ev.source === 'finance' ? 'Finance' : 'Engage'}: {ev.from ? `${titleCase(ev.from)} → ` : ''}<b>{titleCase(ev.to)}</b>
                  {ev.payload?.reason ? ` — ${ev.payload.reason}` : ''}{ev.payload?.utr ? ` · UTR ${ev.payload.utr}` : ''}
                </li>
              ))}
            </ul>
          </>
        ) : <div className="text-sm text-neutral-500">Loading…</div>}
      </Drawer>
    </>
  )
}
