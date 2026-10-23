<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'books',        'name' => 'AICOUNTLY Books',       'category' => 'accounting'],
            ['code' => 'auditor',      'name' => 'AICOUNTLY Auditor',     'category' => 'audit'],
            ['code' => 'fr',           'name' => 'AICOUNTLY FR',          'category' => 'financial_reporting'],
            ['code' => 'secretarial',  'name' => 'AICOUNTLY Secretarial', 'category' => 'compliance'],
            ['code' => 'calendar',     'name' => 'AICOUNTLY Calendar',    'category' => 'productivity'],
            ['code' => 'contacts',     'name' => 'AICOUNTLY Contacts',    'category' => 'productivity'],
            ['code' => 'vault',        'name' => 'AICOUNTLY Vault',       'category' => 'storage'],
            ['code' => 'chat',         'name' => 'AICOUNTLY Chat',        'category' => 'communication'],
            ['code' => 'docs',         'name' => 'AICOUNTLY Docs',        'category' => 'productivity'],
            // Products licensed through Engage's Basic / paid offers (integration 2026-10). Codes are Manage registry keys
            // (CompanyAppRegistry: inventory, billing, notes; Drive is `docs` above). Catalogue identity only — no price,
            // no offer: staff create offers (engage:basic-offers drafts the Basic ones) and publish them.
            ['code' => 'inventory',    'name' => 'AICOUNTLY Inventory',   'category' => 'accounting'],
            ['code' => 'billing',      'name' => 'AICOUNTLY Billing',     'category' => 'accounting'],
            ['code' => 'notes',        'name' => 'AICOUNTLY Notes',       'category' => 'productivity'],
            ['code' => 'hrms',         'name' => 'AICOUNTLY HRMS',        'category' => 'hr'],
            // Every other application in Manage's catalogue (fleet alignment 2026-10): codes = Manage registry keys
            // (AppCatalogService ids; ManageAppRegistry::CATALOG_KEYS), so engage:basic-offers can draft each one's Basic
            // offer. Catalogue identity only — no price, no offer. Names follow the launcher; existing rows above are
            // never touched (Chat = Connect, Docs = Drive keep their earlier names).
            ['code' => 'manage',       'name' => 'AICOUNTLY Manage',       'category' => 'platform'],
            ['code' => 'pos',          'name' => 'AICOUNTLY POS',          'category' => 'sales'],
            ['code' => 'sales',        'name' => 'AICOUNTLY Sales',        'category' => 'sales'],
            ['code' => 'purchases',    'name' => 'AICOUNTLY Purchase',     'category' => 'accounting'],
            ['code' => 'insights',     'name' => 'AICOUNTLY Insights',     'category' => 'analytics'],
            ['code' => 'buddy',        'name' => 'AICOUNTLY AI Pulse',     'category' => 'ai'],
            ['code' => 'remote',       'name' => 'AICOUNTLY Remote',       'category' => 'productivity'],
            ['code' => 'advisor',      'name' => 'AICOUNTLY Advisor',      'category' => 'advisory'],
            ['code' => 'appointments', 'name' => 'AICOUNTLY Appointments', 'category' => 'productivity'],
            ['code' => 'contracts',    'name' => 'AICOUNTLY Contracts',    'category' => 'compliance'],
            ['code' => 'pay',          'name' => 'AICOUNTLY Pay',          'category' => 'payments'],
            ['code' => 'crm',          'name' => 'AICOUNTLY CRM',          'category' => 'sales'],
            ['code' => 'helpdesk',     'name' => 'AICOUNTLY Helpdesk',     'category' => 'support'],
            ['code' => 'receptionist', 'name' => 'AICOUNTLY Lobby',        'category' => 'communication'],
            ['code' => 'valuations',   'name' => 'AICOUNTLY Valuations',   'category' => 'financial_reporting'],
            ['code' => 'email',        'name' => 'AICOUNTLY Email',        'category' => 'communication'],
            ['code' => 'messaging',    'name' => 'AICOUNTLY Messaging',    'category' => 'communication'],
            ['code' => 'voice',        'name' => 'AICOUNTLY Voice',        'category' => 'communication'],
            ['code' => 'edge',         'name' => 'AICOUNTLY Edge',         'category' => 'learning'],
            ['code' => 'aspire',       'name' => 'AICOUNTLY Aspire',       'category' => 'learning'],
            ['code' => 'integrate',    'name' => 'AICOUNTLY Integrate',    'category' => 'developer'],
            ['code' => 'benefits',     'name' => 'AICOUNTLY Benefits',     'category' => 'finance'],
            ['code' => 'capital',      'name' => 'AICOUNTLY Capital',      'category' => 'finance'],
            ['code' => 'apis',         'name' => 'AICOUNTLY APIs',         'category' => 'developer'],
            ['code' => 'community',    'name' => 'AICOUNTLY Community',    'category' => 'community'],
            // Legacy codes kept as they were (not Manage keys; ourpeople folds onto hrms).
            ['code' => 'ourpeople',    'name' => 'AICOUNTLY OurPeople',   'category' => 'hr'],
            ['code' => 'flow',         'name' => 'AICOUNTLY Flow',        'category' => 'internal'],
            ['code' => 'console',      'name' => 'AICOUNTLY Console',     'category' => 'internal'],
            ['code' => 'other',        'name' => 'Other / Custom',        'category' => 'other'],
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
