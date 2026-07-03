import GenericList from '../components/GenericList.jsx'
import { StatusBadge } from '../components/Badges.jsx'
import { formatDate, formatMoney, titleCase } from '../lib/format.js'

export default function Credits() {
  return (
    <GenericList
      title="Credit ledger"
      subtitle="Generic credit ledger — lead credits, wallet, commissions, subscription balances, affiliate rewards."
      resource="credit-ledger"
      filters={[
        { key: 'party_kind', label: 'Party', type: 'select', options: [
          { value: 'lead', label: 'lead' }, { value: 'customer', label: 'customer' },
          { value: 'affiliate', label: 'affiliate' }, { value: 'internal', label: 'internal' },
        ] },
        { key: 'credit_type', label: 'Credit type' },
        { key: 'status', label: 'Status', type: 'select', options: [
          { value: 'posted', label: 'posted' }, { value: 'pending_approval', label: 'pending_approval' },
          { value: 'rejected', label: 'rejected' }, { value: 'reversed', label: 'reversed' },
        ] },
      ]}
      columns={[
        { key: 'created_at', header: 'When', nowrap: true, render: (r) => formatDate(r.created_at, { withTime: true }) },
        { key: 'party_kind', header: 'Party', render: (r) => `${titleCase(r.party_kind)} #${r.party_id ?? '—'}` },
        { key: 'credit_type', header: 'Type', render: (r) => titleCase(r.credit_type) },
        { key: 'direction', header: 'Dir', render: (r) => titleCase(r.direction) },
        { key: 'amount', header: 'Amount', align: 'right', render: (r) => (
          <span className={r.direction === 'credit' ? 'text-emerald-700 font-medium' : 'text-red-700 font-medium'}>
            {r.direction === 'credit' ? '+' : '-'}{formatMoney(r.amount, r.currency)}
          </span>
        ) },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        { key: 'source', header: 'Source' },
      ]}
      formFields={[
        { key: 'party_kind', label: 'Party kind', required: true, type: 'select', options: [
          { value: 'lead', label: 'lead' }, { value: 'customer', label: 'customer' },
          { value: 'affiliate', label: 'affiliate' }, { value: 'internal', label: 'internal' },
        ] },
        { key: 'party_id', label: 'Party ID', type: 'number', required: true },
        { key: 'credit_type', label: 'Credit type', placeholder: 'lead_credit, wallet, commission…' },
        { key: 'direction', label: 'Direction', required: true, type: 'select', options: [
          { value: 'credit', label: 'credit' }, { value: 'debit', label: 'debit' },
        ] },
        { key: 'amount', label: 'Amount', type: 'number', required: true },
        { key: 'currency', label: 'Currency / unit', default: 'USD' },
        { key: 'source', label: 'Source', placeholder: 'reach_campaign, manual, referral…' },
        { key: 'linked_proposal_id', label: 'Linked proposal ID', type: 'number' },
        { key: 'linked_lead_id', label: 'Linked lead ID', type: 'number' },
        { key: 'remarks', label: 'Remarks', type: 'textarea' },
      ]}
    />
  )
}
