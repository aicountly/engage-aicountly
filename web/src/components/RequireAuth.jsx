import { useAuth } from '../lib/auth.jsx'

export default function RequireAuth({ children }) {
  const { user } = useAuth()
  if (!user) {
    return null
  }
  return children
}
