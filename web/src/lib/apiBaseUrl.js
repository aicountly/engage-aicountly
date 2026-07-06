/**
 * Resolve Vite API base URL for axios.
 * Production API lives at /api on engage.aicountly.org (CodeIgniter in public_html/api/).
 */
export function resolveApiBaseUrl(raw) {
  const fallback = '/api'
  const value = (raw ?? '').trim()

  if (!value || value === '/') {
    return fallback
  }

  if (/^https?:\/\//i.test(value)) {
    const url = new URL(value)
    let path = url.pathname.replace(/\/+$/, '') || ''

    if (path === '' || path === '/') {
      path = '/api'
    } else if (!path.endsWith('/api')) {
      path = `${path}/api`
    }

    return `${url.origin}${path}`
  }

  const path = value.replace(/\/+$/, '')
  return path.startsWith('/') ? path : `/${path}`
}
