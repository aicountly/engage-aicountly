import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api, { apiError } from '../../lib/api.js'
import PageHeader from '../../components/PageHeader.jsx'
import OneTimeLink from '../../components/OneTimeLink.jsx'
import { StageBadge, StatusBadge } from '../../components/Badges.jsx'
import { formatDate, titleCase } from '../../lib/format.js'
import { useAuth } from '../../lib/auth.jsx'
import { KYC_REVIEW, PAYOUT_VIEW, SELLER_ADMIN, inr } from '../../lib/sellerRoles.js'

function Field({ label, children, mono }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wide text-neutral-400">{label}</div>
      <div className={mono ? 'font-mono text-sm' : 'text-sm text-neutral-800'}>{children ?? '—'}</div>
    </div>
  )
}

async function openDocument(sellerId, doc) {
  const res = await api.get(`/v1/sellers/${sellerId}/kyc/documents/${doc.id}`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  window.open(url, '_blank', 'noopener')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

export default function SellerDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const { hasRole } = useAuth()
  const canAdmin = hasRole(SELLER_ADMIN)
  const canReview = hasRole(KYC_REVIEW)
  const [error, setError] = useState(null)
  const [delivery, setDelivery] = useState(null)
  const [revealed, setRevealed] = useState(null)

  const q = useQuery({ queryKey: ['seller', id], queryFn: () => api.get(`/v1/sellers/${id}`).then((r) => r.data?.data) })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['seller', id] }); qc.invalidateQueries({ queryKey: ['sellers'] }) }
  const act = useMutation({
    mutationFn: ({ path, body }) => api.post(`/v1/sellers/${id}/${path}`, body || {}).then((r) => r.data?.data),
    onSuccess: (data, vars) => {
      setError(null)
      if (vars.path === 'resend-activation') setDelivery(data?.activation)
      if (vars.path === 'reset-password') setDelivery(data?.reset)
      if (vars.path === 'kyc/reveal') setRevealed(data)
      refresh()
    },
    onError: (e) => setError(apiError(e)),
  })
  const withReason = (path, question) => {
    const reason = window.prompt(question)
    if (reason) act.mutate({ path, body: { reason } })
  }

  if (q.isLoading) return <div className="text-sm text-neutral-500">Loading…</div>
  const s = q.data
  if (!s) return <div className="text-sm text-neutral-500">Seller not found.</div>
  const kyc = s.kyc || {}
  const d = kyc.details

  return (
    <>
      <PageHeader
        title={`${s.name} · ${s.seller_code}`}
        subtitle={[s.organization, s.city, s.state].filter(Boolean).join(' · ') || s.email}
        actions={
          <>
            <Link to="/sellers" className="engage-btn-secondary">Back</Link>
            {canAdmin && ['onboarding', 'kyc_pending', 'active'].includes(s.status) ? <button className="engage-btn-secondary" onClick={() => withReason('suspend', 'Suspend this seller? Reason (shown to the seller):')}>Suspend</button> : null}
            {canAdmin && s.status === 'suspended' ? <button className="engage-btn-primary" onClick={() => withReason('reactivate', 'Reactivate — reason:')}>Reactivate</button> : null}
            {canAdmin && s.status === 'onboarding' && !s.has_password ? <button className="engage-btn-secondary" onClick={() => act.mutate({ path: 'resend-activation' })}>Resend activation</button> : null}
            {canAdmin && s.has_password && s.status !== 'terminated' ? <button className="engage-btn-secondary" onClick={() => { if (window.confirm('Issue a password-reset link and end every session of this seller?')) act.mutate({ path: 'reset-password' }) }}>Reset password</button> : null}
            {canAdmin && s.is_locked ? <button className="engage-btn-secondary" onClick={() => act.mutate({ path: 'unlock' })}>Unlock</button> : null}
            {canAdmin && s.status !== 'terminated' ? <button className="engage-btn-danger" onClick={() => withReason('terminate', 'Terminate this seller permanently? Reason:')}>Terminate</button> : null}
          </>
        }
      />
      {error ? <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div> : null}
      <OneTimeLink delivery={delivery} onDismiss={() => setDelivery(null)} />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card space-y-3">
          <Field label="Status"><StatusBadge status={s.status} /> {s.status_reason ? <span className="text-xs text-neutral-500">— {s.status_reason}</span> : null}</Field>
          <Field label="KYC"><StatusBadge status={s.kyc_status} /></Field>
          <Field label="Email">{s.email}</Field>
          <Field label="Mobile">{s.mobile}</Field>
          <Field label="Activation">{s.has_password ? `Activated ${formatDate(s.activated_at)}` : s.activation_pending ? 'Link issued, not used yet' : 'No live link'}</Field>
          <Field label="Agreement">{s.agreement_accepted_at ? `Accepted ${formatDate(s.agreement_accepted_at, { withTime: true })}` : 'Not accepted'}</Field>
          <Field label="Last sign-in">{formatDate(s.last_login_at, { withTime: true })}</Field>
          <Field label="Failed attempts">{s.failed_attempts}{s.is_locked ? ` · locked until ${formatDate(s.locked_until, { withTime: true })}` : ''}</Field>
          <Field label="Active sessions">{s.active_sessions}</Field>
          {s.lead_id ? <Field label="Application lead"><Link className="text-aicountly-700 hover:underline" to={`/leads/${s.lead_id}`}>Open lead</Link></Field> : null}
        </div>

        <div className="engage-card lg:col-span-2 space-y-3">
          <div className="flex items-center justify-between">
            <div className="font-semibold">KYC</div>
            {canReview && kyc.kyc_status === 'kyc_submitted' ? (
              <div className="flex gap-2">
                <button className="engage-btn-primary text-xs" onClick={() => { if (window.confirm('Mark KYC verified? Check the documents first.')) act.mutate({ path: 'kyc/verify' }) }}>Verify</button>
                <button className="engage-btn-danger text-xs" onClick={() => withReason('kyc/reject', 'Reject KYC — what must the seller fix?')}>Reject</button>
              </div>
            ) : null}
          </div>
          {!d ? <div className="text-sm text-neutral-500">The seller has not entered KYC details yet.</div> : (
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
              <Field label="Legal name">{d.legal_name}</Field>
              <Field label="Entity type">{titleCase(d.entity_type || '')}</Field>
              <Field label="PAN" mono>{revealed?.pan || d.pan_masked}</Field>
              <Field label="GSTIN" mono>{revealed?.gstin || d.gstin_masked || 'Not registered'}</Field>
              <Field label="Address">{[d.address?.line1, d.address?.line2, d.address?.city, d.address?.state, d.address?.pincode].filter(Boolean).join(', ')}</Field>
              <Field label="Bank">{d.bank?.account_holder} · <span className="font-mono">{revealed?.bank_account_number || d.bank?.account_number_masked}</span> · {d.bank?.ifsc}</Field>
            </div>
          )}
          {canReview && d && !revealed ? <button className="engage-btn-secondary text-xs" onClick={() => { if (window.confirm('Show the full PAN / GSTIN / account number? This is audited.')) act.mutate({ path: 'kyc/reveal' }) }}>Show full numbers (audited)</button> : null}
          {kyc.rejection_reason ? <div className="text-sm text-red-700">Last rejection: {kyc.rejection_reason}</div> : null}
          <div>
            <div className="text-[11px] uppercase tracking-wide text-neutral-400 mb-1">Documents (required: {(kyc.required_documents || []).map(titleCase).join(', ')})</div>
            {(kyc.documents || []).length === 0 ? <div className="text-sm text-neutral-500">None uploaded.</div> : (
              <ul className="text-sm space-y-1">
                {kyc.documents.map((doc) => (
                  <li key={doc.id} className="flex items-center gap-2">
                    <span className="w-40">{titleCase(doc.document_type)}</span>
                    <span className="text-neutral-500 text-xs">{doc.file_name} · {Math.round(doc.size_bytes / 1024)} KB · {formatDate(doc.uploaded_at)}</span>
                    {canReview ? <button className="engage-btn-secondary text-xs" onClick={() => openDocument(id, doc).catch((e) => setError(apiError(e)))}>View</button> : null}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <div className="engage-card lg:col-span-2">
          <div className="font-semibold mb-2">Leads attributed to this seller</div>
          {(s.leads || []).length === 0 ? <div className="text-sm text-neutral-500">None yet.</div> : (
            <table className="engage-table">
              <thead><tr><th>Lead</th><th>Stage</th><th>How</th><th>Protected until</th></tr></thead>
              <tbody>
                {s.leads.map((l) => (
                  <tr key={l.id}>
                    <td><Link className="text-aicountly-700 hover:underline" to={`/leads/${l.id}`}>{l.lead_code}</Link> · {l.name}</td>
                    <td><StageBadge stage={l.stage} /></td>
                    <td>{titleCase(l.seller_attribution || '')}</td>
                    <td>{formatDate(l.seller_protection_expires_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
        <div className="engage-card">
          <div className="font-semibold mb-2">Commission</div>
          {Object.keys(s.commission_totals || {}).length === 0 ? <div className="text-sm text-neutral-500">No commission entries.</div> : (
            <ul className="text-sm space-y-1">
              {Object.entries(s.commission_totals).map(([k, v]) => <li key={k} className="flex justify-between"><span>{titleCase(k)} ({v.count})</span><span>{inr(v.amount)}</span></li>)}
            </ul>
          )}
          <Link className="text-xs text-aicountly-700 hover:underline" to={`/commission-entries?seller_id=${s.id}`}>All entries</Link>
          {hasRole(PAYOUT_VIEW) ? (
            <div className="mt-2"><Link className="text-xs text-aicountly-700 hover:underline" to={`/seller-payouts?seller_id=${s.id}`}>Weekly payouts</Link></div>
          ) : null}
        </div>
      </div>
    </>
  )
}
