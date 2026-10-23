<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brand case: product display names are written "Aicountly …", not "AICOUNTLY …" (they reach the seller portal,
 * Manage's licence labels and Sales Bot drafts). Only a row whose name is still exactly the old seeded value is
 * renamed — a name staff changed is left alone. Codes are unchanged, so nothing that matches on code moves.
 */
class RenameProductBrandCase extends Migration
{
    /** code => suffix after the brand (ProductsSeeder). */
    public const PRODUCTS = [
        'books' => 'Books', 'auditor' => 'Auditor', 'fr' => 'FR', 'secretarial' => 'Secretarial', 'calendar' => 'Calendar',
        'contacts' => 'Contacts', 'vault' => 'Vault', 'chat' => 'Chat', 'docs' => 'Docs', 'hrms' => 'HRMS',
        'ourpeople' => 'OurPeople', 'flow' => 'Flow', 'console' => 'Console',
        // Manage catalogue apps added to ProductsSeeder in the 2026-10 fleet alignment.
        'inventory' => 'Inventory', 'billing' => 'Billing', 'notes' => 'Notes', 'manage' => 'Manage', 'pos' => 'POS',
        'sales' => 'Sales', 'purchases' => 'Purchase', 'insights' => 'Insights', 'buddy' => 'AI Pulse', 'remote' => 'Remote',
        'advisor' => 'Advisor', 'appointments' => 'Appointments', 'contracts' => 'Contracts', 'pay' => 'Pay', 'crm' => 'CRM',
        'helpdesk' => 'Helpdesk', 'receptionist' => 'Lobby', 'valuations' => 'Valuations', 'email' => 'Email',
        'messaging' => 'Messaging', 'voice' => 'Voice', 'edge' => 'Edge', 'aspire' => 'Aspire', 'integrate' => 'Integrate',
        'benefits' => 'Benefits', 'capital' => 'Capital', 'apis' => 'APIs', 'community' => 'Community',
    ];

    public function up(): void
    {
        $this->rename('AICOUNTLY ', 'Aicountly ');
    }

    public function down(): void
    {
        $this->rename('Aicountly ', 'AICOUNTLY ');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::PRODUCTS as $code => $suffix) {
            $this->db->query('UPDATE engage_products SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE code = ? AND name = ?',
                [$to . $suffix, $code, $from . $suffix]);
        }
    }
}
