import GenericList from '../components/GenericList.jsx'

export default function LeadSources() {
  return (
    <GenericList
      title="Lead sources"
      subtitle="Named channels used to attribute campaigns and direct leads."
      resource="lead-sources"
      columns={[
        { key: 'code', header: 'Code' },
        { key: 'name', header: 'Name' },
        { key: 'source_type', header: 'Type' },
        { key: 'weight', header: 'Score weight' },
      ]}
      formFields={[
        { key: 'code', label: 'Code', required: true },
        { key: 'name', label: 'Name', required: true },
        { key: 'source_type', label: 'Source type', type: 'select', options: [
          { value: 'reach_campaign', label: 'Reach campaign' }, { value: 'direct', label: 'Direct' },
          { value: 'referral', label: 'Referral' }, { value: 'website', label: 'Website' },
          { value: 'manual', label: 'Manual' }, { value: 'import', label: 'Import' },
          { value: 'webinar', label: 'Webinar' }, { value: 'social', label: 'Social' },
          { value: 'other', label: 'Other' },
        ] },
        { key: 'weight', label: 'Score weight (1-30)', type: 'number', default: 10, help: 'Higher = more trusted / bumps lead score' },
      ]}
    />
  )
}
