import { useQuery } from '@tanstack/react-query'
import api from '../lib/api.js'
import GenericList from '../components/GenericList.jsx'
import { formatMoney } from '../lib/format.js'

export default function Plans() {
  const productsQ = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get('/v1/products').then((r) => r.data?.data?.rows || r.data?.data?.items || r.data?.data || []),
  })
  const products = productsQ.data || []

  return (
    <GenericList
      title="Plans"
      subtitle="Billable plans per product (monthly/annual pricing, seats)."
      resource="plans"
      filters={[
        { key: 'product_id', label: 'Product', type: 'select', options: products.map((p) => ({ value: p.id, label: p.name })) },
      ]}
      columns={[
        { key: 'code', header: 'Code' },
        { key: 'name', header: 'Name' },
        { key: 'product', header: 'Product', render: (r) => r.product_name || r.product_code || r.product_id },
        { key: 'billing_period', header: 'Billing' },
        { key: 'currency', header: 'Ccy' },
        { key: 'list_price', header: 'List price', align: 'right', render: (r) => formatMoney(r.list_price, r.currency) },
      ]}
      formFields={[
        { key: 'product_id', label: 'Product', type: 'select', required: true, options: products.map((p) => ({ value: p.id, label: p.name })) },
        { key: 'code', label: 'Plan code', required: true },
        { key: 'name', label: 'Plan name', required: true },
        { key: 'billing_period', label: 'Billing period', type: 'select', options: [
          { value: 'monthly', label: 'Monthly' }, { value: 'quarterly', label: 'Quarterly' }, { value: 'annual', label: 'Annual' }, { value: 'one_time', label: 'One-time' },
        ], default: 'annual' },
        { key: 'currency', label: 'Currency', default: 'USD' },
        { key: 'list_price', label: 'List price', type: 'number' },
        { key: 'included_users', label: 'Included users', type: 'number' },
        { key: 'description', label: 'Description', type: 'textarea' },
        { key: 'is_active', label: 'Active?', type: 'boolean', default: true, checkboxLabel: 'Available' },
      ]}
    />
  )
}
