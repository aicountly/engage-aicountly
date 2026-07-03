export default function EmptyState({ title = 'Nothing here yet', message, action }) {
  return (
    <div className="text-center py-10 px-6">
      <div className="mx-auto h-10 w-10 rounded-full bg-aicountly-50 text-aicountly-700 flex items-center justify-center">
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
          <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9zM4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5z" />
        </svg>
      </div>
      <div className="mt-2 text-sm font-medium text-neutral-800">{title}</div>
      {message ? <div className="mt-1 text-sm text-neutral-500">{message}</div> : null}
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  )
}
