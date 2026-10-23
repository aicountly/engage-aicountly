// Coupons (docs/COUPONS.md). Role gates mirror server-php Routes.php — the API enforces the same sets.
export const COUPON_VIEW = ['super_admin', 'sales_manager', 'seller_manager'] // view, export / distribute codes, reports
export const COUPON_ADMIN = ['super_admin', 'sales_manager'] // create, edit, activate, pause, retire, generate, quotes
export const DECISION_ROLES = ['super_admin', 'sales_manager'] // legacy discount requests / approvals: approve, reject, execute

export const COUPON_STATUSES = ['draft', 'active', 'paused', 'expired', 'exhausted', 'retired']
export const COUPON_TONES = { draft: 'warning', active: 'success', paused: 'info', expired: 'neutral', exhausted: 'neutral', retired: 'danger' }
export const REDEMPTION_TONES = { reserved: 'info', committed: 'success', released: 'neutral', reversed: 'danger' }

/** 1250 → "12.5%". */
export function percentFromBp(bp) {
  if (bp === null || bp === undefined) return ''
  const s = (Number(bp) / 100).toFixed(2).replace(/\.?0+$/, '')
  return `${s}%`
}

/** "12.5" (percent, as typed) → 1250 basis points, or null. */
export function bpFromPercent(v) {
  const s = String(v ?? '').trim()
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(s)) return null
  const [w, f = ''] = s.split('.')
  const bp = Number(w) * 100 + Number((f + '00').slice(0, 2))
  return bp >= 1 && bp <= 10000 ? bp : null
}

/** "ABCDEFGHJKLM" → "ABCD-EFGH-JKLM". */
export function displayCode(code) {
  return String(code || '').match(/.{1,4}/g)?.join('-') || ''
}

/** The coupon's rule in one line: "20% off (max ₹3,000.00)" / "₹500.00 off". */
export function ruleText(c, formatPaise) {
  if (!c) return ''
  if (c.discount_type === 'percent') {
    return `${percentFromBp(c.percent_bp)} off${c.max_discount_paise ? ` (max ${formatPaise(c.max_discount_paise)})` : ''}`
  }
  return `${formatPaise(c.flat_paise)} off`
}
