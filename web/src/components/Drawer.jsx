import { useEffect } from 'react'

export default function Drawer({ open, title, onClose, children, footer, size = 'lg' }) {
  useEffect(() => {
    if (!open) return
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = prev }
  }, [open])

  if (!open) return null
  const width = size === 'xl' ? 'max-w-3xl' : size === 'md' ? 'max-w-lg' : 'max-w-2xl'
  return (
    <>
      <div className="engage-drawer-backdrop" onClick={onClose} />
      <aside className={`engage-drawer ${width}`}>
        <div className="sticky top-0 z-10 bg-white border-b border-neutral-200 px-5 py-3 flex items-center justify-between">
          <div className="font-semibold text-neutral-900">{title}</div>
          <button className="engage-btn-secondary text-xs" onClick={onClose}>Close</button>
        </div>
        <div className="p-5 space-y-4">{children}</div>
        {footer ? <div className="sticky bottom-0 border-t border-neutral-200 bg-white px-5 py-3 flex justify-end gap-2">{footer}</div> : null}
      </aside>
    </>
  )
}
