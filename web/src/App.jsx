import { Navigate, Route, Routes } from 'react-router-dom'
import RequireAuth from './components/RequireAuth.jsx'
import Layout from './components/Layout.jsx'
import { useAuth } from './lib/auth.jsx'

import ControllerGate from './pages/ControllerGate.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Leads from './pages/Leads.jsx'
import LeadDetail from './pages/LeadDetail.jsx'
import Pipeline from './pages/Pipeline.jsx'
import Accounts from './pages/Accounts.jsx'
import AccountDetail from './pages/AccountDetail.jsx'
import Contacts from './pages/Contacts.jsx'
import Products from './pages/Products.jsx'
import Plans from './pages/Plans.jsx'
import LeadSources from './pages/LeadSources.jsx'
import Campaigns from './pages/Campaigns.jsx'
import LicensingInterests from './pages/LicensingInterests.jsx'
import SubscriptionInquiries from './pages/SubscriptionInquiries.jsx'
import Proposals from './pages/Proposals.jsx'
import ProposalDetail from './pages/ProposalDetail.jsx'
import DiscountRequests from './pages/DiscountRequests.jsx'
import FollowUps from './pages/FollowUps.jsx'
import Communications from './pages/Communications.jsx'
import Renewals from './pages/Renewals.jsx'
import Credits from './pages/Credits.jsx'
import BotSettings from './pages/BotSettings.jsx'
import BotQueue from './pages/BotQueue.jsx'
import BotReports from './pages/BotReports.jsx'
import BotReportDetail from './pages/BotReportDetail.jsx'
import BotActions from './pages/BotActions.jsx'
import LocalBotReports from './pages/LocalBotReports.jsx'
import Approvals from './pages/Approvals.jsx'
import AuditLogs from './pages/AuditLogs.jsx'
import Settings from './pages/Settings.jsx'
import Health from './pages/Health.jsx'
import ConsoleSync from './pages/ConsoleSync.jsx'
import WorkerStatus from './pages/WorkerStatus.jsx'

export default function App() {
  const { user, loading, ssoPending } = useAuth()

  if (loading || ssoPending) {
    return (
      <div className="grid h-screen place-items-center text-sm text-neutral-500">
        {ssoPending ? 'Signing you in from Console…' : 'Loading Engage Portal…'}
      </div>
    )
  }

  if (!user) {
    return <ControllerGate />
  }

  return (
    <Routes>
      <Route path="/login" element={<Navigate to="/" replace />} />
      <Route element={<RequireAuth><Layout /></RequireAuth>}>
        <Route index element={<Dashboard />} />
        <Route path="/pipeline" element={<Pipeline />} />

        <Route path="/leads" element={<Leads />} />
        <Route path="/leads/:id" element={<LeadDetail />} />

        <Route path="/accounts" element={<Accounts />} />
        <Route path="/accounts/:id" element={<AccountDetail />} />
        <Route path="/contacts" element={<Contacts />} />
        <Route path="/lead-sources" element={<LeadSources />} />
        <Route path="/campaigns" element={<Campaigns />} />

        <Route path="/products" element={<Products />} />
        <Route path="/plans" element={<Plans />} />
        <Route path="/licensing-interests" element={<LicensingInterests />} />
        <Route path="/subscription-inquiries" element={<SubscriptionInquiries />} />
        <Route path="/proposals" element={<Proposals />} />
        <Route path="/proposals/:id" element={<ProposalDetail />} />
        <Route path="/discount-requests" element={<DiscountRequests />} />
        <Route path="/renewals" element={<Renewals />} />

        <Route path="/follow-ups" element={<FollowUps />} />
        <Route path="/communication-drafts" element={<Communications />} />
        <Route path="/credit-ledger" element={<Credits />} />

        <Route path="/bot/queue" element={<BotQueue />} />
        <Route path="/bot/reports" element={<BotReports />} />
        <Route path="/bot/reports/:id" element={<BotReportDetail />} />
        <Route path="/bot/reports-local" element={<LocalBotReports />} />
        <Route path="/bot/settings" element={<BotSettings />} />
        <Route path="/bot/actions" element={<BotActions />} />

        <Route path="/approvals" element={<Approvals />} />
        <Route path="/audit-logs" element={<AuditLogs />} />

        <Route path="/console-sync" element={<ConsoleSync />} />
        <Route path="/worker-status" element={<WorkerStatus />} />
        <Route path="/health" element={<Health />} />

        <Route path="/settings" element={<Settings />} />

        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
