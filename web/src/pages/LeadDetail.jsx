import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import BotScoreCard from '../components/BotScoreCard.jsx'
import { PriorityBadge, StageBadge, SourceBadge } from '../components/Badges.jsx'
import { BotReportTimeline, FollowUpTimeline, LeadActivityTimeline } from '../components/Timelines.jsx'
import ProposalPanel from '../components/ProposalPanel.jsx'
import CreditLedgerPanel from '../components/CreditLedgerPanel.jsx'
import { formatDate, titleCase } from '../lib/format.js'

const BOT_ACTIONS = [
  { code: 'qualify_lead', label: 'Qualify lead' },
  { code: 'score_lead', label: 'Score lead' },
  { code: 'recommend_follow_up', label: 'Recommend follow-up' },
  { code: 'draft_email', label: 'Draft email' },
  { code: 'draft_whatsapp', label: 'Draft WhatsApp' },
  { code: 'draft_proposal_summary', label: 'Draft proposal summary' },
  { code: 'suggest_pricing', label: 'Suggest pricing / discount' },
  { code: 'update_stage', label: 'Suggest CRM stage' },
  { code: 'schedule_follow_up', label: 'Schedule follow-up' },
  { code: 'identify_hot', label: 'Identify hot' },
  { code: 'identify_stale', label: 'Identify stale' },
]

export default function LeadDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const [botMsg, setBotMsg] = useState(null)

  const leadQ = useQuery({
    queryKey: ['lead', id],
    queryFn: () => api.get(`/v1/leads/${id}`).then((r) => r.data?.data || r.data),
  })
  const actQ = useQuery({
    queryKey: ['lead-activities', id],
    queryFn: () => api.get(`/v1/leads/${id}/activities`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const followUpsQ = useQuery({
    queryKey: ['lead-follow-ups', id],
    queryFn: () => api.get(`/v1/follow-ups?lead_id=${id}&per_page=50`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const proposalsQ = useQuery({
    queryKey: ['lead-proposals', id],
    queryFn: () => api.get(`/v1/proposals?lead_id=${id}`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const creditsQ = useQuery({
    queryKey: ['lead-credits', id],
    queryFn: () => api.get(`/v1/credit-ledger?party_kind=lead&party_id=${id}`).then((r) => r.data?.data || r.data),
  })
  const reportsQ = useQuery({
    queryKey: ['lead-bot-reports', id],
    queryFn: () => api.get(`/v1/bot/reports?subject_kind=lead&subject_id=${id}&per_page=25`).then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const stagesQ = useQuery({
    queryKey: ['pipeline-stages'],
    queryFn: () => api.get('/v1/pipeline-stages').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })

  const runBot = useMutation({
    mutationFn: ({ action }) => api.post('/v1/bot/queue', { action_code: action, lead_id: Number(id) }).then((r) => r.data),
    onSuccess: (res, vars) => {
      setBotMsg({ ok: true, message: `Queued ${vars.action} · report #${res?.data?.report?.id ?? '?'}` })
      qc.invalidateQueries({ queryKey: ['lead', id] })
      qc.invalidateQueries({ queryKey: ['lead-activities', id] })
      qc.invalidateQueries({ queryKey: ['lead-bot-reports', id] })
      qc.invalidateQueries({ queryKey: ['lead-follow-ups', id] })
    },
    onError: (e) => setBotMsg({ ok: false, message: apiError(e) }),
  })

  const moveStage = useMutation({
    mutationFn: (stage) => api.post(`/v1/leads/${id}/move-stage`, { stage }).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['lead', id] })
      qc.invalidateQueries({ queryKey: ['lead-activities', id] })
    },
  })

  const addNote = useMutation({
    mutationFn: (note) => api.post(`/v1/leads/${id}/note`, { note }).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['lead-activities', id] }),
  })

  if (leadQ.isLoading) return <div>Loading…</div>
  if (leadQ.isError) return <div className="text-red-700">{apiError(leadQ.error)}</div>
  const lead = leadQ.data?.lead || leadQ.data || {}

  return (
    <>
      <PageHeader
        title={lead.name || `Lead #${lead.id}`}
        subtitle={`${lead.lead_code || ''} · ${lead.organization || lead.email || '—'}`}
        actions={
          <>
            <button className="engage-btn-secondary" onClick={() => nav(-1)}>Back</button>
            <Link to="/leads" className="engage-btn-secondary">All leads</Link>
          </>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2 space-y-4">
          <div className="engage-card">
            <div className="flex flex-wrap items-center gap-2 mb-3">
              <StageBadge stage={lead.stage} />
              <PriorityBadge priority={lead.priority} />
              <SourceBadge source={lead.source_type} />
              {lead.interested_product ? <span className="engage-pill bg-neutral-50 border-neutral-200 text-neutral-700">{lead.interested_product}</span> : null}
            </div>
            <dl className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
              <div><dt className="text-xs text-neutral-500">Email</dt><dd className="font-medium text-neutral-900 break-all">{lead.email || '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">Mobile</dt><dd className="font-medium text-neutral-900">{lead.mobile || '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">WhatsApp</dt><dd className="font-medium text-neutral-900">{lead.whatsapp || '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">Expected users</dt><dd className="font-medium text-neutral-900">{lead.expected_users ?? '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">Expected companies</dt><dd className="font-medium text-neutral-900">{lead.expected_companies ?? '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">Sales status</dt><dd className="font-medium text-neutral-900">{titleCase(lead.sales_status || '—')}</dd></div>
              <div><dt className="text-xs text-neutral-500">Source campaign</dt><dd className="font-medium text-neutral-900 break-all">{lead.source_campaign || '—'}</dd></div>
              <div><dt className="text-xs text-neutral-500">Last contacted</dt><dd className="font-medium text-neutral-900">{formatDate(lead.last_contacted_at, { withTime: true })}</dd></div>
              <div><dt className="text-xs text-neutral-500">Next follow-up</dt><dd className="font-medium text-neutral-900">{formatDate(lead.next_follow_up_date)}</dd></div>
            </dl>
            {lead.notes ? (
              <div className="mt-3">
                <div className="text-xs uppercase tracking-wide text-neutral-500 mb-1">Notes</div>
                <div className="text-sm text-neutral-800 whitespace-pre-wrap">{lead.notes}</div>
              </div>
            ) : null}
          </div>

          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Bot report timeline</div>
            <BotReportTimeline items={reportsQ.data || []} />
          </div>

          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Activity</div>
            <LeadActivityTimeline items={actQ.data || []} />
            <NoteForm onSubmit={(n) => addNote.mutate(n)} pending={addNote.isPending} />
          </div>

          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Follow-ups</div>
            <FollowUpTimeline items={followUpsQ.data || []} />
          </div>

          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Proposals</div>
            <ProposalPanel proposals={proposalsQ.data || []} onOpen={(p) => nav(`/proposals/${p.id}`)} />
          </div>

          <CreditLedgerPanel entries={creditsQ.data?.rows || creditsQ.data || []} balance={creditsQ.data?.balance} />
        </div>

        <div className="space-y-4">
          <BotScoreCard
            score={lead.lead_score}
            bucket={lead.score_bucket}
            conversion={lead.conversion_probability}
            factors={leadQ.data?.score_factors}
            bot_summary={lead.bot_summary}
          />
          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Sales bot actions</div>
            <div className="grid grid-cols-2 gap-2">
              {BOT_ACTIONS.map((a) => (
                <button
                  key={a.code}
                  disabled={runBot.isPending}
                  className="engage-btn-secondary text-xs justify-center"
                  onClick={() => { setBotMsg(null); runBot.mutate({ action: a.code }) }}
                >
                  {a.label}
                </button>
              ))}
            </div>
            {botMsg ? (
              <div className={`mt-3 text-sm rounded px-2 py-1 border ${botMsg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>
                {botMsg.message}
              </div>
            ) : null}
          </div>

          <div className="engage-card">
            <div className="text-sm font-semibold text-neutral-900 mb-2">Change stage</div>
            <div className="grid grid-cols-2 gap-2">
              {(stagesQ.data || []).map((s) => (
                <button
                  key={s.code}
                  disabled={moveStage.isPending || lead.stage === s.code}
                  className={`engage-btn-secondary text-xs justify-center ${lead.stage === s.code ? 'bg-aicountly-50 border-aicountly-400 text-aicountly-800' : ''}`}
                  onClick={() => moveStage.mutate(s.code)}
                >
                  {s.name}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}

function NoteForm({ onSubmit, pending }) {
  const [note, setNote] = useState('')
  return (
    <form
      className="mt-3 flex items-start gap-2"
      onSubmit={(e) => { e.preventDefault(); if (note.trim()) { onSubmit(note.trim()); setNote('') } }}
    >
      <textarea rows={2} className="engage-input flex-1" placeholder="Add a note (also written to audit log)…" value={note} onChange={(e) => setNote(e.target.value)} />
      <button type="submit" className="engage-btn-primary" disabled={pending || !note.trim()}>Add note</button>
    </form>
  )
}
