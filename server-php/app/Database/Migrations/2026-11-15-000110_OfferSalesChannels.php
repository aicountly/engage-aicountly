<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Where an offer is sold (docs/SELLING_LICENCES.md §2.3, §5). Additive.
 *
 *   engage_offers.channels   subset of {website, manage, seller}:
 *                              website  listed on www.aicountly.com (GET v1/website/catalogue)
 *                              manage   in Manage's catalogue (GET v1/manage/catalogue) — the Basic (default) offer of a
 *                                       product always is, whatever its channels, because Manage's plans_sync needs it
 *                              seller   sellers can buy it in seller.aicountly.com (priced offers only)
 *                            Staff can always issue any published offer. Channels are visibility, not commercial terms,
 *                            so they stay changeable on a published version (they are not in engage_offers_guard).
 *
 * New offers default to all three. Offers that exist when this runs keep exactly where they were sold before — Manage
 * only — so nothing appears on the website or in the seller portal until staff choose it.
 */
class OfferSalesChannels extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS channels TEXT[] NOT NULL DEFAULT '{website,manage,seller}'");
        $db->query("UPDATE engage_offers SET channels = '{manage}' WHERE created_at < NOW() AND channels = '{website,manage,seller}'");
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_channels_chk');
        $db->query("ALTER TABLE engage_offers ADD CONSTRAINT engage_offers_channels_chk CHECK (channels <@ ARRAY['website','manage','seller']::text[])");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_channels_chk');
        $this->db->query('ALTER TABLE engage_offers DROP COLUMN IF EXISTS channels');
    }
}
