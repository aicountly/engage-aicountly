<?php

namespace Tests\Feature;

use Tests\Support\LicensingTestCase;

/**
 * The Settings screen saves one setting at a time as {key, value} (web/src/pages/Settings.jsx). That form must change
 * the setting it names — it used to be stored as two settings called "key" and "value" — and the map form
 * {name: value, …} keeps working.
 *
 * @internal
 */
final class SettingsUpdateTest extends LicensingTestCase
{
    public function testTheSettingsScreenChangesTheSettingItNames(): void
    {
        $admin = $this->staff(['super_admin'], 'root@aicountly.org');

        $r = $this->asStaff($admin, 'put', 'v1/settings', ['key' => 'seller_order_payment_instructions', 'value' => 'HDFC Bank 50200012345678, IFSC HDFC0001234']);
        $this->assertSame(200, $this->code($r));

        $this->assertSame('HDFC Bank 50200012345678, IFSC HDFC0001234', $this->setting('seller_order_payment_instructions'));
        $this->assertNull($this->setting('key'), 'no setting called "key"');
        $this->assertNull($this->setting('value'), 'no setting called "value"');

        $this->assertSame(200, $this->code($this->asStaff($admin, 'put', 'v1/settings', ['key' => 'seller_order_validity_days', 'value' => 5])));
        $this->assertSame(5, $this->setting('seller_order_validity_days'));
    }

    public function testAMapStillSetsSeveral(): void
    {
        $admin = $this->staff(['super_admin'], 'root@aicountly.org');

        $r = $this->asStaff($admin, 'put', 'v1/settings', ['seller_order_validity_days' => 3, 'seller_order_payment_instructions' => 'UPI aicountly@hdfcbank']);
        $this->assertSame(200, $this->code($r));
        $this->assertSame(3, $this->setting('seller_order_validity_days'));
        $this->assertSame('UPI aicountly@hdfcbank', $this->setting('seller_order_payment_instructions'));
    }

    public function testANamelessSettingIsRefused(): void
    {
        $r = $this->asStaff($this->staff(['super_admin'], 'root@aicountly.org'), 'put', 'v1/settings', ['key' => ' ', 'value' => 'x']);

        $this->assertSame(422, $this->code($r));
        $this->assertSame('validation_failed', $this->json($r)['code']);
    }

    public function testASalesManagerCannotChangeSettings(): void
    {
        $r = $this->asStaff($this->admin(), 'put', 'v1/settings', ['key' => 'seller_order_validity_days', 'value' => 1]);

        $this->assertSame(403, $this->code($r));
        $this->assertSame(7, $this->setting('seller_order_validity_days'));
    }

    private function setting(string $key): mixed
    {
        $row = $this->db()->table('engage_settings')->where('key', $key)->get()->getRowArray();

        return $row === null ? null : json_decode((string) $row['value_json'], true);
    }
}
