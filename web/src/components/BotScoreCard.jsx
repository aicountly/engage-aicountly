import { ScoreBadge } from './Badges.jsx'

export default function BotScoreCard({ score, bucket, conversion, factors, bot_summary }) {
  return (
    <div className="engage-card">
      <div className="flex items-center justify-between">
        <div>
          <div className="text-xs uppercase tracking-wide text-neutral-500">Bot score</div>
          <div className="text-2xl font-semibold text-neutral-900 mt-0.5">{score ?? '—'}</div>
        </div>
        <ScoreBadge score={score} bucket={bucket} />
      </div>
      <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
        <div>
          <div className="text-xs text-neutral-500">Conversion probability</div>
          <div className="font-medium text-neutral-900">{conversion != null ? `${Math.round(Number(conversion) * 100)}%` : '—'}</div>
        </div>
        <div>
          <div className="text-xs text-neutral-500">Bucket</div>
          <div className="font-medium text-neutral-900 capitalize">{bucket || '—'}</div>
        </div>
      </div>
      {Array.isArray(factors) && factors.length ? (
        <div className="mt-3">
          <div className="text-xs uppercase tracking-wide text-neutral-500 mb-1">Score factors</div>
          <ul className="text-sm text-neutral-700 list-disc pl-5 space-y-0.5">
            {factors.map((f, i) => <li key={i}>{typeof f === 'string' ? f : (f.reason || JSON.stringify(f))}</li>)}
          </ul>
        </div>
      ) : null}
      {bot_summary ? (
        <div className="mt-3">
          <div className="text-xs uppercase tracking-wide text-neutral-500 mb-1">Bot summary</div>
          <div className="text-sm text-neutral-800 whitespace-pre-wrap">{bot_summary}</div>
        </div>
      ) : null}
    </div>
  )
}
