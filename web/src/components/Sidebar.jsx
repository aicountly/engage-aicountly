import { NavLink } from 'react-router-dom'
import clsx from 'clsx'

const groups = [
  {
    title: 'Overview',
    items: [
      { to: '/', label: 'Dashboard' },
      { to: '/pipeline', label: 'Pipeline' },
    ],
  },
  {
    title: 'Sales',
    items: [
      { to: '/leads', label: 'Leads' },
      { to: '/accounts', label: 'Accounts' },
      { to: '/contacts', label: 'Contacts' },
      { to: '/lead-sources', label: 'Lead sources' },
      { to: '/campaigns', label: 'Campaigns' },
    ],
  },
  {
    title: 'Licensing & subscriptions',
    items: [
      { to: '/products', label: 'Products' },
      { to: '/plans', label: 'Plans' },
      { to: '/licensing-interests', label: 'Licensing interests' },
      { to: '/subscription-inquiries', label: 'Subscription inquiries' },
      { to: '/proposals', label: 'Proposals' },
      { to: '/discount-requests', label: 'Pricing / discount requests' },
      { to: '/renewals', label: 'Renewals' },
    ],
  },
  {
    title: 'Engagement',
    items: [
      { to: '/follow-ups', label: 'Follow-ups' },
      { to: '/communication-drafts', label: 'Communication drafts' },
    ],
  },
  {
    title: 'Credits',
    items: [
      { to: '/credit-ledger', label: 'Credit ledger' },
    ],
  },
  {
    title: 'Sales bot',
    items: [
      { to: '/bot/queue', label: 'Bot queue' },
      { to: '/bot/reports', label: 'Bot reports' },
      { to: '/bot/settings', label: 'Bot settings' },
      { to: '/bot/actions', label: 'Bot actions' },
    ],
  },
  {
    title: 'Approvals & governance',
    items: [
      { to: '/approvals', label: 'Approvals' },
      { to: '/audit-logs', label: 'Audit logs' },
    ],
  },
  {
    title: 'Integrations',
    items: [
      { to: '/console-sync', label: 'Console sync' },
      { to: '/worker-status', label: 'Worker status' },
      { to: '/health', label: 'API health' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { to: '/settings', label: 'Settings' },
    ],
  },
]

export default function Sidebar({ open, onClose }) {
  return (
    <>
      {/* Mobile backdrop */}
      <div
        className={clsx('fixed inset-0 z-30 bg-black/30 lg:hidden', open ? 'block' : 'hidden')}
        onClick={onClose}
      />
      <aside
        className={clsx(
          'fixed lg:static z-40 h-full w-64 shrink-0 border-r border-neutral-200 bg-white transition-transform duration-200 ease-out',
          open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        )}
      >
        <div className="flex items-center gap-3 px-5 py-4 border-b border-neutral-200">
          <div className="h-9 w-9 rounded-lg bg-aicountly-600 flex items-center justify-center text-white font-bold text-sm">EN</div>
          <div>
            <div className="text-sm font-semibold text-neutral-900">AICOUNTLY</div>
            <div className="text-xs text-aicountly-700 -mt-0.5 font-medium">Engage portal</div>
          </div>
        </div>
        <nav className="px-3 py-4 space-y-5 overflow-y-auto h-[calc(100vh-64px)]">
          {groups.map((g) => (
            <div key={g.title}>
              <div className="px-2 mb-1 text-[10px] font-semibold uppercase tracking-wider text-neutral-400">
                {g.title}
              </div>
              <div className="space-y-0.5">
                {g.items.map((it) => (
                  <NavLink
                    key={it.to}
                    to={it.to}
                    end={it.to === '/'}
                    onClick={onClose}
                    className={({ isActive }) =>
                      clsx(
                        'block rounded-md px-3 py-1.5 text-sm font-medium',
                        isActive
                          ? 'bg-aicountly-50 text-aicountly-800 border-l-2 border-aicountly-600 pl-[10px]'
                          : 'text-neutral-700 hover:bg-neutral-50 hover:text-aicountly-800',
                      )
                    }
                  >
                    {it.label}
                  </NavLink>
                ))}
              </div>
            </div>
          ))}
        </nav>
      </aside>
    </>
  )
}
