import { CONSOLE_WEB_URL } from '../lib/consoleAuth.js'

export const ROLES_MANAGED_TEXT = 'Roles are assigned in Console → Roles & Access'

/**
 * Engage does not edit staff roles: Console (console.aicountly.org → Roles &
 * Access) assigns them and Engage mirrors them at sign-in / token refresh.
 */
export default function RolesManagedNote({ className = '' }) {
  return (
    <div className={`rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600 ${className}`}>
      {ROLES_MANAGED_TEXT}{' '}
      (<a className="text-aicountly-700 underline" href={CONSOLE_WEB_URL} target="_blank" rel="noreferrer">{CONSOLE_WEB_URL.replace(/^https?:\/\//, '')}</a>).
    </div>
  )
}
