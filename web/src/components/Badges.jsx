import clsx from 'clsx'
import { titleCase } from '../lib/format.js'

const stageStyles = {
  new: 'bg-neutral-100 text-neutral-800 border-neutral-200',
  qualified: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  contacted: 'bg-sky-50 text-sky-800 border-sky-200',
  demo_required: 'bg-amber-50 text-amber-800 border-amber-200',
  proposal_required: 'bg-indigo-50 text-indigo-800 border-indigo-200',
  proposal_sent: 'bg-blue-50 text-blue-800 border-blue-200',
  negotiation: 'bg-violet-50 text-violet-800 border-violet-200',
  waiting_for_approval: 'bg-orange-50 text-orange-800 border-orange-200',
  converted: 'bg-green-100 text-green-900 border-green-300',
  lost: 'bg-red-50 text-red-800 border-red-200',
  nurture: 'bg-teal-50 text-teal-800 border-teal-200',
  not_relevant: 'bg-neutral-50 text-neutral-500 border-neutral-200',
}

export function StageBadge({ stage, colour }) {
  const cls = stageStyles[stage] || 'bg-neutral-100 text-neutral-800 border-neutral-200'
  return (
    <span
      className={clsx('engage-pill', cls)}
      style={colour ? { borderColor: colour, color: colour } : undefined}
    >
      {titleCase(stage || 'unknown')}
    </span>
  )
}

const priorityStyles = {
  low: 'bg-neutral-50 text-neutral-600 border-neutral-200',
  normal: 'bg-sky-50 text-sky-700 border-sky-200',
  high: 'bg-amber-50 text-amber-800 border-amber-200',
  urgent: 'bg-red-50 text-red-800 border-red-300',
}
export function PriorityBadge({ priority }) {
  const cls = priorityStyles[priority] || priorityStyles.normal
  return <span className={clsx('engage-pill', cls)}>{titleCase(priority || 'normal')}</span>
}

export function ScoreBadge({ score, bucket }) {
  const n = Number(score ?? 0)
  const b = bucket || (n >= 75 ? 'hot' : n >= 50 ? 'warm' : n > 0 ? 'cold' : 'unscored')
  const cls = b === 'hot' ? 'bg-red-50 text-red-800 border-red-200'
    : b === 'warm' ? 'bg-amber-50 text-amber-800 border-amber-200'
    : b === 'cold' ? 'bg-sky-50 text-sky-800 border-sky-200'
    : 'bg-neutral-50 text-neutral-600 border-neutral-200'
  return (
    <span className={clsx('engage-pill', cls)} title={`Bucket: ${b}`}>
      {Number.isFinite(n) ? `${n}` : '—'} · {titleCase(b)}
    </span>
  )
}

const approvalStyles = {
  pending: 'bg-amber-50 text-amber-800 border-amber-300',
  approved: 'bg-green-50 text-green-800 border-green-300',
  rejected: 'bg-red-50 text-red-800 border-red-300',
  executed: 'bg-emerald-50 text-emerald-800 border-emerald-300',
  cancelled: 'bg-neutral-50 text-neutral-600 border-neutral-200',
  auto_approved: 'bg-blue-50 text-blue-800 border-blue-300',
  not_required: 'bg-neutral-50 text-neutral-500 border-neutral-200',
}
export function ApprovalBadge({ status }) {
  const cls = approvalStyles[status] || approvalStyles.pending
  return <span className={clsx('engage-pill', cls)}>{titleCase(status || 'pending')}</span>
}

export function SourceBadge({ source }) {
  if (!source) return null
  const label = typeof source === 'string' ? source : (source.name || source.code)
  return (
    <span className="engage-pill bg-aicountly-50 text-aicountly-800 border-aicountly-200">
      {titleCase(label)}
    </span>
  )
}

export function BotModeBadge({ mode }) {
  const m = mode === 'auto' ? 'auto' : 'confirm'
  const cls = m === 'auto'
    ? 'bg-emerald-100 text-emerald-800 border-emerald-300'
    : 'bg-amber-100 text-amber-800 border-amber-300'
  return (
    <span className={clsx('engage-pill', cls)}>
      Bot: {m.toUpperCase()} mode
    </span>
  )
}

export function StatusBadge({ status }) {
  if (!status) return null
  const s = String(status).toLowerCase()
  const cls =
    ['sent', 'approved', 'active', 'won', 'converted', 'ok', 'healthy'].includes(s) ? 'bg-green-50 text-green-800 border-green-200' :
    ['rejected', 'lost', 'failed', 'error'].includes(s) ? 'bg-red-50 text-red-800 border-red-200' :
    ['pending', 'draft', 'queued', 'pending_approval', 'waiting'].includes(s) ? 'bg-amber-50 text-amber-800 border-amber-200' :
    ['negotiation', 'sent', 'expired'].includes(s) ? 'bg-violet-50 text-violet-800 border-violet-200' :
    'bg-neutral-50 text-neutral-700 border-neutral-200'
  return <span className={clsx('engage-pill', cls)}>{titleCase(status)}</span>
}
