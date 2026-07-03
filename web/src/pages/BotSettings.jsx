import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import { BotModeBadge } from '../components/Badges.jsx'

const AUTO_ACTIONS = [
  'score_lead', 'qualify_lead', 'identify_hot', 'identify_stale', 'update_stage',
  'schedule_follow_up', 'recommend_follow_up', 'convert_reach_lead',
  'draft_email', 'draft_whatsapp', 'draft_proposal_summary', 'suggest_pricing',
  'prepare_renewal',
]

export default function BotSettings() {
  const qc = useQueryClient()
  const [state, setState] = useState({ bot_mode: 'confirm', allowed_auto_actions: [], notes: '' })
  const [msg, setMsg] = useState(null)

  const q = useQuery({
    queryKey: ['bot-settings'],
    queryFn: () => api.get('/v1/bot/settings').then((r) => r.data?.data || r.data),
  })

  useEffect(() => {
    if (!q.data) return
    setState({
      bot_mode: q.data.bot_mode || q.data.mode || 'confirm',
      allowed_auto_actions: q.data.allowed_auto_actions || [],
      notes: q.data.notes || '',
    })
  }, [q.data])

  const save = useMutation({
    mutationFn: (payload) => api.put('/v1/bot/settings', payload).then((r) => r.data),
    onSuccess: () => { setMsg({ ok: true, text: 'Bot settings saved. Console will receive the mode update.' }); qc.invalidateQueries({ queryKey: ['bot-settings'] }) },
    onError: (e) => setMsg({ ok: false, text: apiError(e) }),
  })

  function toggle(a) {
    const list = state.allowed_auto_actions.includes(a)
      ? state.allowed_auto_actions.filter((x) => x !== a)
      : [...state.allowed_auto_actions, a]
    setState({ ...state, allowed_auto_actions: list })
  }

  return (
    <>
      <PageHeader
        title="Sales bot settings"
        subtitle="Choose whether the bot must ask a superadmin (Confirm) or can act (Auto) on selected low-risk actions."
        actions={<BotModeBadge mode={state.bot_mode} />}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card lg:col-span-2 space-y-4">
          <div>
            <div className="engage-label">Bot mode</div>
            <div className="grid grid-cols-2 gap-2 mt-1">
              <button
                onClick={() => setState({ ...state, bot_mode: 'confirm' })}
                className={`engage-btn justify-center ${state.bot_mode === 'confirm' ? 'bg-aicountly-600 text-white' : 'engage-btn-secondary'}`}
              >
                Confirm mode
              </button>
              <button
                onClick={() => setState({ ...state, bot_mode: 'auto' })}
                className={`engage-btn justify-center ${state.bot_mode === 'auto' ? 'bg-aicountly-600 text-white' : 'engage-btn-secondary'}`}
              >
                Auto mode
              </button>
            </div>
            <div className="text-xs text-neutral-500 mt-2">
              Confirm mode: every non-trivial action needs a superadmin approval.<br/>
              Auto mode: the bot may run the actions checked below without asking. Everything is still audited.
            </div>
          </div>

          <div>
            <div className="engage-label">Allowed auto actions</div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mt-1">
              {AUTO_ACTIONS.map((a) => (
                <label key={a} className="flex items-center gap-2 text-sm border rounded-md px-2 py-1.5 border-neutral-200">
                  <input type="checkbox" checked={state.allowed_auto_actions.includes(a)} onChange={() => toggle(a)} />
                  <span className="text-neutral-800">{a}</span>
                </label>
              ))}
            </div>
          </div>

          <div>
            <div className="engage-label">Operator notes</div>
            <textarea className="engage-input" rows={2} value={state.notes} onChange={(e) => setState({ ...state, notes: e.target.value })} />
          </div>

          {msg ? (
            <div className={`rounded-md text-sm px-3 py-2 border ${msg.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'}`}>
              {msg.text}
            </div>
          ) : null}

          <div className="flex justify-end">
            <button className="engage-btn-primary" onClick={() => { setMsg(null); save.mutate(state) }} disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save settings'}
            </button>
          </div>
        </div>

        <div className="engage-card space-y-2">
          <div className="text-sm font-semibold text-neutral-900">High-risk actions</div>
          <p className="text-sm text-neutral-600">These always require an approval, even in Auto mode:</p>
          <ul className="text-sm text-neutral-700 list-disc pl-5 space-y-1">
            <li>Send external message (email / WhatsApp)</li>
            <li>Apply pricing discount</li>
            <li>Mark lead as converted</li>
            <li>Create subscription / license proposal</li>
            <li>Large credit ledger adjustment</li>
            <li>Change auto-mode configuration</li>
          </ul>
        </div>
      </div>
    </>
  )
}
