export const CONSOLE_WEB_URL = (import.meta.env.VITE_CONSOLE_URL || 'https://console.aicountly.org').replace(/\/$/, '')

export function consoleLoginUrl(returnOrigin) {
  const origin = returnOrigin || window.location.origin
  return `${CONSOLE_WEB_URL}/login?return=${encodeURIComponent(origin + '/')}`
}

export function redirectToConsoleLogin() {
  window.location.replace(consoleLoginUrl())
}
