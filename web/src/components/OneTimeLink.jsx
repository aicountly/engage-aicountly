/**
 * Shows the result of an activation / reset link delivery. When Engage could not email it,
 * the link is displayed here exactly once — Engage keeps only its hash.
 */
export default function OneTimeLink({ delivery, onDismiss }) {
  if (!delivery) return null
  if (delivery.email_sent) {
    return (
      <div className="mb-4 rounded-md bg-green-50 border border-green-200 text-green-900 text-sm px-3 py-2">
        {delivery.message}
        <button className="engage-btn-secondary ml-3 text-xs" onClick={onDismiss}>Dismiss</button>
      </div>
    )
  }
  return (
    <div className="mb-4 rounded-md bg-amber-50 border border-amber-300 text-amber-900 text-sm px-3 py-3">
      <div className="font-semibold">No email was sent — copy this link now (shown once)</div>
      <div className="mt-1 font-mono text-xs break-all select-all">{delivery.link}</div>
      <div className="mt-1 text-xs">{delivery.message}</div>
      <button className="engage-btn-secondary mt-2" onClick={onDismiss}>I have copied it</button>
    </div>
  )
}
