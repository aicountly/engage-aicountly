import { ScoreBadge, PriorityBadge } from './Badges.jsx'
import { formatDate, truncate } from '../lib/format.js'

export default function KanbanBoard({ columns, leadsByStage = {}, onCardClick, onMove }) {
  return (
    <div className="flex gap-3 overflow-x-auto pb-4">
      {columns.map((c) => {
        const items = leadsByStage[c.code] || []
        return (
          <div key={c.code} className="w-72 shrink-0 bg-neutral-100 rounded-lg border border-neutral-200">
            <div
              className="px-3 py-2 border-b border-neutral-200 flex items-center justify-between rounded-t-lg"
              style={c.colour ? { borderTop: `3px solid ${c.colour}` } : { borderTop: '3px solid #16a34a' }}
            >
              <div className="text-sm font-semibold text-neutral-800">{c.name}</div>
              <div className="text-xs text-neutral-500">{items.length}</div>
            </div>
            <div className="p-2 space-y-2 min-h-[100px]">
              {items.length === 0 ? (
                <div className="text-xs text-neutral-400 text-center py-6">No leads</div>
              ) : items.map((l) => (
                <div
                  key={l.id}
                  className="bg-white rounded-md border border-neutral-200 p-2.5 shadow-sm cursor-pointer hover:border-aicountly-400"
                  onClick={() => onCardClick?.(l)}
                >
                  <div className="text-sm font-medium text-neutral-900">{l.name}</div>
                  <div className="text-xs text-neutral-500 truncate">{l.organization || l.email || '—'}</div>
                  <div className="mt-1.5 flex items-center gap-1.5 flex-wrap">
                    <ScoreBadge score={l.lead_score} bucket={l.score_bucket} />
                    <PriorityBadge priority={l.priority} />
                  </div>
                  {l.next_follow_up_date ? (
                    <div className="mt-1.5 text-[11px] text-neutral-500">Next: {formatDate(l.next_follow_up_date)}</div>
                  ) : null}
                  {l.bot_summary ? (
                    <div className="mt-1.5 text-[11px] text-neutral-600 leading-snug">
                      <span className="font-semibold text-aicountly-700">Bot:</span> {truncate(l.bot_summary, 100)}
                    </div>
                  ) : null}
                  {onMove && c.code !== 'converted' && c.code !== 'lost' ? (
                    <div className="mt-2 flex gap-1 flex-wrap">
                      {columns.filter((x) => x.code !== c.code).slice(0, 3).map((tgt) => (
                        <button
                          key={tgt.code}
                          className="text-[10px] px-1.5 py-0.5 rounded border border-neutral-200 text-neutral-600 hover:border-aicountly-400 hover:text-aicountly-700"
                          onClick={(e) => { e.stopPropagation(); onMove(l, tgt.code) }}
                        >
                          → {tgt.name}
                        </button>
                      ))}
                    </div>
                  ) : null}
                </div>
              ))}
            </div>
          </div>
        )
      })}
    </div>
  )
}
