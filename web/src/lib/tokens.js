// Token conversion rates (docs/TOKEN_CONVERSIONS.md). Role gates mirror server-php Routes.php —
// the API enforces the same sets, these only decide what is worth rendering.
export const TOKEN_VIEW = ['super_admin', 'sales_manager', 'seller_manager']
export const TOKEN_ADMIN = ['super_admin', 'sales_manager']

export const RATE_STATUSES = ['draft', 'active', 'retired']
export const RATE_TONES = { draft: 'warning', active: 'success', retired: 'neutral' }

export const ONE_X = 10000

/** 250000 → "25x", 15000 → "1.5x". Mirrors TokenRateCard::describe so both sides read alike. */
export function multiplierText(bp) {
  if (bp === null || bp === undefined || bp === '') return ''
  const n = Number(bp)
  if (!Number.isFinite(n)) return ''
  if (n % ONE_X === 0) return `${n / ONE_X}x`
  return `${String(Number((n / ONE_X).toFixed(4)))}x`
}

/** "25" → 250000. Staff type a multiplier; the API stores basis points. */
export function bpFromMultiplier(value) {
  const n = Number(value)
  return Number.isFinite(n) ? Math.round(n * ONE_X) : null
}

/** Where a model's multiplier came from, in words a human can act on. */
export function sourceText(source) {
  if (!source) return ''
  if (source === 'model') return 'Rate set for this model'
  if (source === 'provider') return 'Provider default'
  if (source === 'unclassified') return 'No rate, no tier — charged at the fallback'
  if (source.startsWith('tier:')) return `No rate — ${source.slice(5)} tier fallback`
  return source
}

export const TIER_LABELS = { low: 'Low — economical', medium: 'Medium — general purpose', high: 'High — frontier / reasoning' }

/** Rupees from paise, for display only. */
export function rupees(paise) {
  const n = Number(paise)
  return Number.isFinite(n) ? (n / 100).toLocaleString('en-IN', { style: 'currency', currency: 'INR' }) : ''
}
