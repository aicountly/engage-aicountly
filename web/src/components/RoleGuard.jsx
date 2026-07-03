import { useAuth } from '../lib/auth.jsx'

export default function RoleGuard({ roles = [], children, fallback = null }) {
  const { user } = useAuth()
  const userRoles = user?.roles || []
  const ok = roles.length === 0 || roles.some((r) => userRoles.includes(r))
  if (!ok) return fallback
  return children
}
