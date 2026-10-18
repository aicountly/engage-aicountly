// Licensing (docs/LICENSING.md). Role gates mirror server-php Routes.php: read = every signed-in role.
export const LICENSING_ADMIN = ['super_admin', 'sales_manager']

const IST = 'Asia/Kolkata'

/** ₹1,18,000.00 from integer paise (en-IN grouping). */
export function formatPaise(paise) {
  if (paise === null || paise === undefined || paise === '') return '—'
  const n = Number(paise) / 100
  if (!Number.isFinite(n)) return '—'
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)
}

/** "1180.50" (rupees, as typed) → 118050 paise, or null when not a valid amount. */
export function rupeesToPaise(v) {
  const s = String(v ?? '').trim().replace(/,/g, '')
  if (!/^\d{1,13}(\.\d{1,2})?$/.test(s)) return null
  const [r, p = ''] = s.split('.')
  return Number(r) * 100 + Number((p + '00').slice(0, 2))
}

export function paiseToRupees(paise) {
  if (paise === null || paise === undefined) return ''
  const n = Number(paise)
  return `${Math.floor(n / 100)}.${String(n % 100).padStart(2, '0')}`
}

/** dd/mm/yyyy (IST). */
export function formatDateIN(iso, { withTime = false } = {}) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso)
  const opts = { timeZone: IST, day: '2-digit', month: '2-digit', year: 'numeric' }
  if (withTime) Object.assign(opts, { hour: '2-digit', minute: '2-digit', hour12: false })
  return new Intl.DateTimeFormat('en-IN', opts).format(d)
}

/** ends_at is exclusive: the last day of the term is the day before (dd/mm/yyyy). */
export function lastDayIN(endsAtIso) {
  if (!endsAtIso) return 'No end date'
  const d = new Date(endsAtIso)
  if (Number.isNaN(d.getTime())) return String(endsAtIso)
  return formatDateIN(new Date(d.getTime() - 1000).toISOString())
}

/** Today in IST as YYYY-MM-DD (for <input type="date">). */
export function todayIST() {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  return parts
}

export const TONE_CLASSES = {
  success: 'bg-green-50 text-green-800 border-green-200',
  info: 'bg-sky-50 text-sky-800 border-sky-200',
  warning: 'bg-amber-50 text-amber-800 border-amber-200',
  danger: 'bg-red-50 text-red-800 border-red-200',
  neutral: 'bg-neutral-100 text-neutral-700 border-neutral-200',
}

export function termText(months, days) {
  const p = []
  if (months) p.push(`${months} month${months === 1 ? '' : 's'}`)
  if (days) p.push(`${days} day${days === 1 ? '' : 's'}`)
  return p.length ? p.join(' ') : 'No end date'
}

/** Parse a JSON textarea; throws a readable error. */
export function parseJsonField(text, label, { expect = 'object' } = {}) {
  const t = String(text ?? '').trim()
  if (t === '') return expect === 'array' ? [] : {}
  let v
  try { v = JSON.parse(t) } catch { throw new Error(`${label} must be valid JSON.`) }
  if (expect === 'array' && !Array.isArray(v)) throw new Error(`${label} must be a JSON list [ … ].`)
  if (expect === 'object' && (v === null || typeof v !== 'object' || Array.isArray(v))) throw new Error(`${label} must be a JSON object { … }.`)
  return v
}
