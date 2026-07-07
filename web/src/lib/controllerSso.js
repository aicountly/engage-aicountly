/** Read Console controller SSO token from URL hash (#controller_sso=...). */
export function readControllerSsoToken() {
  const hash = window.location.hash.startsWith('#')
    ? window.location.hash.slice(1)
    : window.location.hash
  if (!hash) return ''

  const params = new URLSearchParams(hash)
  const fromParams = params.get('controller_sso')
  if (fromParams) return fromParams

  const match = hash.match(/(?:^|&)controller_sso=([^&]+)/)
  if (!match?.[1]) return ''

  try {
    return decodeURIComponent(match[1])
  } catch {
    return match[1]
  }
}

/** Remove SSO token from the address bar after it has been consumed. */
export function clearControllerSsoHash() {
  const { pathname, search } = window.location
  window.history.replaceState(null, '', `${pathname}${search}`)
}
