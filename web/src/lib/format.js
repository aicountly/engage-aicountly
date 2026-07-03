export function formatDate(iso, { withTime = false } = {}) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return String(iso)
    const opts = withTime
      ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
      : { day: '2-digit', month: 'short', year: 'numeric' }
    return new Intl.DateTimeFormat(undefined, opts).format(d)
  } catch { return String(iso) }
}

export function formatMoney(v, ccy = 'USD') {
  if (v === null || v === undefined || v === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: ccy || 'USD', maximumFractionDigits: 2 }).format(Number(v))
  } catch { return `${v} ${ccy}` }
}

export function truncate(s, n = 80) {
  if (!s) return ''
  const str = String(s)
  return str.length > n ? str.slice(0, n - 1) + '…' : str
}

export function titleCase(s) {
  if (!s) return ''
  return String(s).replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}
