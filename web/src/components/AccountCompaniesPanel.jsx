import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { pickRows } from '../lib/api.js'
import Drawer from './Drawer.jsx'
import DataTable from './DataTable.jsx'
import { ErrorNote, describeError } from './LicensingBits.jsx'
import { useAuth } from '../lib/auth.jsx'
import { LICENSING_ADMIN, formatDateIN } from '../lib/licensing.js'

/**
 * Engage account ↔ Manage company (cmp_id). Staff check the company themselves; the mapping is recorded
 * as "verified manually by <you>". A company belongs to one account at a time.
 * With accountId the panel is scoped to that account (Account page); without, it lists every mapping.
 */
export default function AccountCompaniesPanel({ accountId = null, title = 'Manage companies' }) {
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canEdit = hasRole(LICENSING_ADMIN)
  const [form, setForm] = useState(null)
  const [unlink, setUnlink] = useState(null)
  const [error, setError] = useState(null)
  const [accountQ, setAccountQ] = useState('')
  const key = ['account-companies', accountId || 'all']
  const q = useQuery({ queryKey: key, queryFn: () => api.get('/v1/account-companies', { params: accountId ? { account_id: accountId } : {} }).then((r) => r.data?.data?.items || []) })
  const accounts = useQuery({
    queryKey: ['accounts-pick', accountQ],
    enabled: !!form && !accountId,
    queryFn: () => api.get('/v1/accounts', { params: { q: accountQ, limit: 20 } }).then(pickRows),
  })
  const done = () => { setForm(null); setUnlink(null); setError(null); qc.invalidateQueries({ queryKey: ['account-companies'] }) }
  const link = useMutation({
    mutationFn: (f) => api.post('/v1/account-companies', { account_id: Number(accountId || f.account_id), cmp_id: String(f.cmp_id).trim(), company_name: f.company_name, verification_note: f.verification_note }),
    onSuccess: done,
    onError: (e) => setError(describeError(e)),
  })
  const doUnlink = useMutation({
    mutationFn: (u) => api.post(`/v1/account-companies/${u.id}/unlink`, { reason: u.reason }),
    onSuccess: done,
    onError: (e) => setError(describeError(e)),
  })

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <div className="text-sm font-semibold">{title}</div>
        {canEdit ? <button className="engage-btn-secondary text-xs" onClick={() => { setError(null); setForm({ account_id: '', cmp_id: '', company_name: '', verification_note: '' }) }}>+ Map a company</button> : null}
      </div>
      <ErrorNote error={!form && !unlink ? error : null} />
      <DataTable
        loading={q.isLoading}
        rows={q.data || []}
        emptyTitle="No Manage company mapped"
        emptyMessage="Map the customer's Manage company id before issuing a licence."
        columns={[
          { key: 'cmp_id', header: 'cmp_id', render: (m) => <Link className="font-mono text-aicountly-700 hover:underline" to={`/licences?cmp_id=${m.cmp_id}`}>{m.cmp_id}</Link> },
          { key: 'company_name', header: 'Company' },
          ...(accountId ? [] : [{ key: 'account_name', header: 'Account', render: (m) => <Link className="text-aicountly-700 hover:underline" to={`/accounts/${m.account_id}`}>{m.account_name}</Link> }]),
          { key: 'status', header: 'Status', render: (m) => (m.status === 'active' ? 'Linked' : `Unlinked ${formatDateIN(m.unlinked_at)}`) },
          { key: 'verified_by_display', header: 'Verification', render: (m) => <span title={m.verification_note || ''}>{m.verified_by_display} · {formatDateIN(m.verified_at)}</span> },
          { key: 'licences_count', header: 'Licences', align: 'right' },
          ...(canEdit ? [{ key: 'x', header: '', render: (m) => (m.status === 'active' ? <button className="engage-btn-secondary text-xs" onClick={() => { setError(null); setUnlink({ id: m.id, cmp_id: m.cmp_id, reason: '' }) }}>Unlink</button> : null) }] : []),
        ]}
      />
      <Drawer open={!!form} size="md" title="Map a Manage company" onClose={() => setForm(null)}
        footer={<button className="engage-btn-primary" disabled={link.isPending} onClick={() => link.mutate(form)}>Map company</button>}>
        {form ? <>
          <ErrorNote error={error} />
          {!accountId ? <div>
            <div className="engage-label">Account</div>
            <input className="engage-input mb-1" placeholder="Search accounts…" value={accountQ} onChange={(e) => setAccountQ(e.target.value)} />
            <select className="engage-input" value={form.account_id} onChange={(e) => setForm({ ...form, account_id: e.target.value })}>
              <option value="">Choose…</option>{(accounts.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </div> : null}
          <div><div className="engage-label">Manage company id (cmp_id)</div><input className="engage-input font-mono" inputMode="numeric" value={form.cmp_id} onChange={(e) => setForm({ ...form, cmp_id: e.target.value })} /></div>
          <div><div className="engage-label">Company name (as in Manage)</div><input className="engage-input" value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} /></div>
          <div>
            <div className="engage-label">How did you verify it?</div>
            <textarea className="engage-input" rows={3} placeholder="e.g. Owner shared the company id from Manage → Company settings on a call on 18/10/2026." value={form.verification_note} onChange={(e) => setForm({ ...form, verification_note: e.target.value })} />
            <div className="text-xs text-neutral-500 mt-1">Engage cannot check Manage yet — this is recorded as verified manually by you.</div>
          </div>
        </> : null}
      </Drawer>
      <Drawer open={!!unlink} size="md" title={`Unlink company ${unlink?.cmp_id ?? ''}`} onClose={() => setUnlink(null)}
        footer={<button className="engage-btn-danger" disabled={doUnlink.isPending} onClick={() => doUnlink.mutate(unlink)}>Unlink</button>}>
        {unlink ? <>
          <ErrorNote error={error} />
          <div className="text-sm text-neutral-600">Refused while the company still has a live licence of this account.</div>
          <div><div className="engage-label">Reason</div><textarea className="engage-input" rows={3} value={unlink.reason} onChange={(e) => setUnlink({ ...unlink, reason: e.target.value })} /></div>
        </> : null}
      </Drawer>
    </div>
  )
}
