import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import api, { apiError } from '../lib/api.js'
import PageHeader from '../components/PageHeader.jsx'
import Drawer from '../components/Drawer.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, titleCase } from '../lib/format.js'

const EDIT_FIELDS = [
  { key: 'name', label: 'Partner name', required: true },
  { key: 'contact_name', label: 'Contact person' },
  { key: 'email', label: 'Email', type: 'email', required: true },
  { key: 'phone', label: 'Phone' },
  { key: 'partner_type', label: 'Partner type' },
  { key: 'website', label: 'Website', type: 'url' },
  { key: 'country', label: 'Country' },
  { key: 'city', label: 'City' },
  { key: 'notes', label: 'Notes', type: 'textarea' },
]

export default function PartnerDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(false)
  const [credentials, setCredentials] = useState(false)
  const [generated, setGenerated] = useState(null)

  const { data: p, isLoading } = useQuery({
    queryKey: ['partner', id],
    queryFn: () => api.get(`/v1/partners/${id}`).then((r) => r.data?.data),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['partner', id] })
    qc.invalidateQueries({ queryKey: ['partners'] })
  }

  const save = useMutation({
    mutationFn: (payload) => api.put(`/v1/partners/${id}`, payload).then((r) => r.data),
    onSuccess: () => { setEditing(false); setError(null); refresh() },
    onError: (e) => setError(apiError(e)),
  })

  const setStatus = useMutation({
    mutationFn: (action) => api.post(`/v1/partners/${id}/${action}`).then((r) => r.data),
    onSuccess: refresh,
    onError: (e) => setError(apiError(e)),
  })

  const setPassword = useMutation({
    mutationFn: (payload) => api.post(`/v1/partners/${id}/password`, payload).then((r) => r.data?.data),
    onSuccess: (data) => {
      setCredentials(false)
      setError(null)
      setGenerated(data?.generated_password || null)
      refresh()
    },
    onError: (e) => setError(apiError(e)),
  })

  const remove = useMutation({
    mutationFn: () => api.delete(`/v1/partners/${id}`).then((r) => r.data),
    onSuccess: () => navigate('/partners'),
    onError: (e) => setError(apiError(e)),
  })

  const restore = useMutation({
    mutationFn: () => api.post(`/v1/partners/${id}/restore`).then((r) => r.data),
    onSuccess: refresh,
    onError: (e) => setError(apiError(e)),
  })

  if (isLoading) return <div className="text-sm text-neutral-500">Loading…</div>
  if (!p) return <div className="text-sm text-neutral-500">Partner not found.</div>

  const isDeleted = !!p.deleted_at
  const isLocked = p.locked_until && new Date(p.locked_until) > new Date()

  return (
    <>
      <PageHeader
        title={p.name}
        subtitle={[p.partner_type ? titleCase(p.partner_type) : null, p.country, p.city].filter(Boolean).join(' · ') || '—'}
        actions={
          <>
            <Link to="/partners" className="engage-btn-secondary">Back</Link>
            {isDeleted ? (
              <button className="engage-btn-primary" onClick={() => restore.mutate()} disabled={restore.isPending}>
                Restore partner
              </button>
            ) : (
              <>
                <button className="engage-btn-secondary" onClick={() => { setError(null); setEditing(true) }}>Edit</button>
                <button
                  className="engage-btn-secondary"
                  onClick={() => setStatus.mutate(p.status === 'active' ? 'deactivate' : 'activate')}
                  disabled={setStatus.isPending}
                >
                  {p.status === 'active' ? 'Deactivate' : 'Activate'}
                </button>
                <button className="engage-btn-primary" onClick={() => { setError(null); setCredentials(true) }}>
                  {p.has_portal_access ? 'Reset password' : 'Set password'}
                </button>
                <button
                  className="engage-btn-danger"
                  disabled={remove.isPending}
                  onClick={() => {
                    if (window.confirm(`Delete partner "${p.name}"? They will immediately lose access to partner.aicountly.com. This can be undone from the deleted list.`)) {
                      remove.mutate()
                    }
                  }}
                >
                  Delete
                </button>
              </>
            )}
          </>
        }
      />

      {error ? (
        <div className="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2">{error}</div>
      ) : null}

      {isDeleted ? (
        <div className="mb-4 rounded-md bg-neutral-100 border border-neutral-300 text-neutral-700 text-sm px-3 py-2">
          This partner was deleted on {formatDate(p.deleted_at, { withTime: true })} and can no longer sign in to the Partner Portal.
        </div>
      ) : null}

      {generated ? (
        <div className="mb-4 rounded-md bg-amber-50 border border-amber-300 text-amber-900 text-sm px-3 py-3">
          <div className="font-semibold">Generated password — shown once</div>
          <div className="mt-1 font-mono text-base">{generated}</div>
          <div className="mt-1 text-xs">
            Share it with the partner over a secure channel. Engage stores only a hash and cannot show it again.
          </div>
          <button className="engage-btn-secondary mt-2" onClick={() => setGenerated(null)}>Dismiss</button>
        </div>
      ) : null}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="engage-card lg:col-span-1 space-y-3">
          <Field label="Partner ID" mono>{p.partner_uid}</Field>
          <Field label="Status"><StatusBadge status={p.status} /></Field>
          <Field label="Email">{p.email}</Field>
          <Field label="Phone">{p.phone || '—'}</Field>
          <Field label="Contact person">{p.contact_name || '—'}</Field>
          <Field label="Website">
            {p.website
              ? <a className="text-aicountly-700 hover:underline" href={p.website} target="_blank" rel="noreferrer">{p.website}</a>
              : '—'}
          </Field>
          <Field label="Created">{formatDate(p.created_at, { withTime: true })}</Field>
          <Field label="Updated">{formatDate(p.updated_at, { withTime: true })}</Field>
        </div>

        <div className="lg:col-span-2 space-y-4">
          <div className="engage-card space-y-3">
            <div className="text-sm font-semibold text-neutral-900">Partner Portal access</div>
            <Field label="Credentials">
              {p.has_portal_access
                ? <span className="engage-pill bg-green-50 text-green-800 border-green-200">Set {formatDate(p.password_set_at)}</span>
                : <span className="engage-pill bg-neutral-50 text-neutral-600 border-neutral-200">Not set — partner cannot sign in</span>}
            </Field>
            <Field label="Can sign in now">
              {!isDeleted && p.status === 'active' && p.has_portal_access && !isLocked ? 'Yes' : 'No'}
            </Field>
            <Field label="Last login">
              {p.last_login_at ? `${formatDate(p.last_login_at, { withTime: true })} · ${p.last_login_ip || 'unknown IP'}` : 'Never'}
            </Field>
            <Field label="Failed attempts">
              {p.failed_attempts ?? 0}
              {isLocked ? (
                <>
                  {' '}
                  <span className="engage-pill bg-red-50 text-red-800 border-red-200">
                    Locked until {formatDate(p.locked_until, { withTime: true })}
                  </span>
                  <button
                    className="engage-btn-secondary ml-2"
                    onClick={() => api.post(`/v1/partners/${id}/unlock`).then(refresh).catch((e) => setError(apiError(e)))}
                  >
                    Unlock
                  </button>
                </>
              ) : null}
            </Field>
            <p className="text-xs text-neutral-500">
              Partners sign in at partner.aicountly.com with this email address. There is no signup — access exists only
              once a password is set here, and stops the moment the partner is deactivated or deleted.
            </p>
          </div>

          {p.notes ? (
            <div className="engage-card">
              <div className="text-xs uppercase tracking-wide text-neutral-500">Notes</div>
              <div className="text-sm text-neutral-800 whitespace-pre-wrap mt-1">{p.notes}</div>
            </div>
          ) : null}
        </div>
      </div>

      {editing ? (
        <Drawer open title="Edit partner" onClose={() => setEditing(false)}>
          <EditForm
            initial={p}
            submitting={save.isPending}
            onSubmit={(payload) => { setError(null); save.mutate(payload) }}
          />
        </Drawer>
      ) : null}

      {credentials ? (
        <Drawer open title="Partner Portal password" onClose={() => setCredentials(false)}>
          <CredentialsForm
            submitting={setPassword.isPending}
            onSubmit={(payload) => { setError(null); setPassword.mutate(payload) }}
          />
        </Drawer>
      ) : null}
    </>
  )
}

function Field({ label, children, mono }) {
  return (
    <div>
      <div className="text-xs text-neutral-500">{label}</div>
      <div className={mono ? 'text-sm font-mono break-all' : 'text-sm font-medium text-neutral-900'}>{children}</div>
    </div>
  )
}

function EditForm({ initial, onSubmit, submitting }) {
  const [state, setState] = useState(() => {
    const s = {}
    for (const f of EDIT_FIELDS) s[f.key] = initial?.[f.key] ?? ''
    return s
  })

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit(Object.fromEntries(EDIT_FIELDS.map((f) => [f.key, state[f.key] === '' ? null : state[f.key]])))
      }}
    >
      {EDIT_FIELDS.map((f) => (
        <div key={f.key}>
          <label className="engage-label">{f.label}{f.required ? ' *' : ''}</label>
          {f.type === 'textarea' ? (
            <textarea
              className="engage-input"
              rows={3}
              value={state[f.key] ?? ''}
              onChange={(e) => setState({ ...state, [f.key]: e.target.value })}
            />
          ) : (
            <input
              className="engage-input"
              type={f.type || 'text'}
              required={f.required}
              value={state[f.key] ?? ''}
              onChange={(e) => setState({ ...state, [f.key]: e.target.value })}
            />
          )}
        </div>
      ))}
      <div className="pt-2 flex justify-end">
        <button type="submit" className="engage-btn-primary" disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </form>
  )
}

function CredentialsForm({ onSubmit, submitting }) {
  const [password, setPassword] = useState('')

  return (
    <div className="space-y-4">
      <div>
        <button
          type="button"
          className="engage-btn-primary w-full"
          disabled={submitting}
          onClick={() => onSubmit({ generate: true })}
        >
          Generate a strong password
        </button>
        <p className="text-xs text-neutral-500 mt-1">
          Engage shows the generated password once and stores only a hash of it.
        </p>
      </div>

      <div className="text-xs uppercase tracking-wide text-neutral-400 text-center">or set one manually</div>

      <form
        className="space-y-3"
        onSubmit={(e) => { e.preventDefault(); onSubmit({ password }) }}
      >
        <div>
          <label className="engage-label">New password</label>
          <input
            className="engage-input"
            type="password"
            autoComplete="new-password"
            minLength={10}
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <div className="text-xs text-neutral-500 mt-1">
            At least 10 characters, including a letter and a number.
          </div>
        </div>
        <div className="flex justify-end">
          <button type="submit" className="engage-btn-secondary" disabled={submitting || password.length < 10}>
            {submitting ? 'Saving…' : 'Set password'}
          </button>
        </div>
      </form>
    </div>
  )
}
