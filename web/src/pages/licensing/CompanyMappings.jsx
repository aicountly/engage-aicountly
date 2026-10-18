import PageHeader from '../../components/PageHeader.jsx'
import AccountCompaniesPanel from '../../components/AccountCompaniesPanel.jsx'

export default function CompanyMappings() {
  return (
    <>
      <PageHeader
        title="Account ↔ Manage company"
        subtitle="Which Engage account each Manage company (cmp_id) belongs to. Licences are issued to a mapped company only."
      />
      <AccountCompaniesPanel title="All mappings" />
    </>
  )
}
