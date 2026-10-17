// Seller programme role gates — the API enforces the same sets (server-php Routes.php, ConsoleRoleSync).
export const SELLER_VIEW = ['super_admin', 'sales_manager', 'seller_manager', 'kyc_reviewer']
export const SELLER_ADMIN = ['super_admin', 'seller_manager']
export const KYC_REVIEW = ['super_admin', 'seller_manager', 'kyc_reviewer']
export const COMMISSION_ADMIN = ['super_admin', 'sales_manager']
export const ATTRIBUTION_ADMIN = ['super_admin', 'sales_manager', 'seller_manager']

export const inr = (v) => (v === null || v === undefined || v === '' ? '—' : `₹${v}`)
