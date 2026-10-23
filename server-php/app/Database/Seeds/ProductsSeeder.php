<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'books',        'name' => 'Aicountly Books',       'category' => 'accounting'],
            ['code' => 'auditor',      'name' => 'Aicountly Auditor',     'category' => 'audit'],
            ['code' => 'fr',           'name' => 'Aicountly FR',          'category' => 'financial_reporting'],
            ['code' => 'secretarial',  'name' => 'Aicountly Secretarial', 'category' => 'compliance'],
            ['code' => 'calendar',     'name' => 'Aicountly Calendar',    'category' => 'productivity'],
            ['code' => 'contacts',     'name' => 'Aicountly Contacts',    'category' => 'productivity'],
            ['code' => 'vault',        'name' => 'Aicountly Vault',       'category' => 'storage'],
            ['code' => 'chat',         'name' => 'Aicountly Chat',        'category' => 'communication'],
            ['code' => 'docs',         'name' => 'Aicountly Docs',        'category' => 'productivity'],
            // Products licensed through Engage's Basic / paid offers (integration 2026-10). Codes are Manage registry keys
            // (CompanyAppRegistry: inventory, billing, notes; Drive is `docs` above). Catalogue identity only — no price,
            // no offer: staff create offers (engage:basic-offers drafts the Basic ones) and publish them.
            ['code' => 'inventory',    'name' => 'Aicountly Inventory',   'category' => 'accounting'],
            ['code' => 'billing',      'name' => 'Aicountly Billing',     'category' => 'accounting'],
            ['code' => 'notes',        'name' => 'Aicountly Notes',       'category' => 'productivity'],
            ['code' => 'hrms',         'name' => 'Aicountly HRMS',        'category' => 'hr'],
            // Every other application in Manage's catalogue (fleet alignment 2026-10): codes = Manage registry keys
            // (AppCatalogService ids; ManageAppRegistry::CATALOG_KEYS), so engage:basic-offers can draft each one's Basic
            // offer. Catalogue identity only — no price, no offer. Names follow the launcher; existing rows above are
            // never touched (Chat = Connect, Docs = Drive keep their earlier names).
            ['code' => 'manage',       'name' => 'Aicountly Manage',       'category' => 'platform'],
            ['code' => 'pos',          'name' => 'Aicountly POS',          'category' => 'sales'],
            ['code' => 'sales',        'name' => 'Aicountly Sales',        'category' => 'sales'],
            ['code' => 'purchases',    'name' => 'Aicountly Purchase',     'category' => 'accounting'],
            ['code' => 'insights',     'name' => 'Aicountly Insights',     'category' => 'analytics'],
            ['code' => 'buddy',        'name' => 'Aicountly AI Pulse',     'category' => 'ai'],
            ['code' => 'remote',       'name' => 'Aicountly Remote',       'category' => 'productivity'],
            ['code' => 'advisor',      'name' => 'Aicountly Advisor',      'category' => 'advisory'],
            ['code' => 'appointments', 'name' => 'Aicountly Appointments', 'category' => 'productivity'],
            ['code' => 'contracts',    'name' => 'Aicountly Contracts',    'category' => 'compliance'],
            ['code' => 'pay',          'name' => 'Aicountly Pay',          'category' => 'payments'],
            ['code' => 'crm',          'name' => 'Aicountly CRM',          'category' => 'sales'],
            ['code' => 'helpdesk',     'name' => 'Aicountly Helpdesk',     'category' => 'support'],
            ['code' => 'receptionist', 'name' => 'Aicountly Lobby',        'category' => 'communication'],
            ['code' => 'valuations',   'name' => 'Aicountly Valuations',   'category' => 'financial_reporting'],
            ['code' => 'email',        'name' => 'Aicountly Email',        'category' => 'communication'],
            ['code' => 'messaging',    'name' => 'Aicountly Messaging',    'category' => 'communication'],
            ['code' => 'voice',        'name' => 'Aicountly Voice',        'category' => 'communication'],
            // Edge and Aspire are Manage registry keys and also Aicountly Academy courses (leads, fee payments, seller
            // commission): one row each, category Academy (migration 2026-10-24-000010_AddAcademyProducts).
            ['code' => 'edge',         'name' => 'Aicountly Edge',         'category' => 'Academy'],
            ['code' => 'aspire',       'name' => 'Aicountly Aspire',       'category' => 'Academy'],
            ['code' => 'integrate',    'name' => 'Aicountly Integrate',    'category' => 'developer'],
            ['code' => 'benefits',     'name' => 'Aicountly Benefits',     'category' => 'finance'],
            ['code' => 'capital',      'name' => 'Aicountly Capital',      'category' => 'finance'],
            ['code' => 'apis',         'name' => 'Aicountly APIs',         'category' => 'developer'],
            ['code' => 'community',    'name' => 'Aicountly Community',    'category' => 'community'],
            // Legacy codes kept as they were (not Manage keys; ourpeople folds onto hrms).
            ['code' => 'ourpeople',    'name' => 'Aicountly OurPeople',   'category' => 'hr'],
            ['code' => 'flow',         'name' => 'Aicountly Flow',        'category' => 'internal'],
            ['code' => 'console',      'name' => 'Aicountly Console',     'category' => 'internal'],
            ['code' => 'other',        'name' => 'Other / Custom',        'category' => 'other'],
            // Aicountly Academy (umbrella course product; not a Manage registry key) — migration 2026-10-24-000010_AddAcademyProducts.
            ['code' => 'academy',      'name' => 'Aicountly Academy',     'category' => 'Academy'],
        ];

        $now = date('Y-m-d H:i:s');
        $sort = 10;
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_products')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                $sort += 10; // re-runs keep existing rows untouched and give new ones a slot after them
                continue;
            }
            $row['sort_order'] = $sort;
            $row['is_active']  = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('engage_products')->insert($row);
            $sort += 10;
        }
    }
}
