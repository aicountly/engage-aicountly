import EmptyState from './EmptyState.jsx'

export default function DataTable({
  columns,
  rows,
  keyField = 'id',
  loading = false,
  onRowClick,
  emptyMessage = 'No data yet.',
  emptyTitle = 'Nothing to show',
  actions,
}) {
  return (
    <div className="engage-card p-0 overflow-hidden">
      {actions ? <div className="flex items-center justify-between px-4 py-2 border-b border-neutral-100 bg-neutral-50">{actions}</div> : null}
      <div className="overflow-x-auto">
        <table className="engage-table">
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} style={c.width ? { width: c.width } : undefined} className={c.align === 'right' ? 'text-right' : ''}>
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={columns.length} className="text-center text-neutral-400 py-6">Loading…</td>
              </tr>
            ) : (rows?.length ?? 0) === 0 ? (
              <tr>
                <td colSpan={columns.length} className="p-0">
                  <EmptyState title={emptyTitle} message={emptyMessage} />
                </td>
              </tr>
            ) : rows.map((r) => (
              <tr
                key={r[keyField]}
                className={onRowClick ? 'cursor-pointer hover:bg-aicountly-50/40' : ''}
                onClick={onRowClick ? () => onRowClick(r) : undefined}
              >
                {columns.map((c) => (
                  <td key={c.key} className={c.align === 'right' ? 'text-right whitespace-nowrap' : c.nowrap ? 'whitespace-nowrap' : ''}>
                    {c.render ? c.render(r) : (r[c.key] ?? '—')}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
