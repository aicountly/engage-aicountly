<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use App\Libraries\Licensing\EntitlementCodeCatalogue;

/**
 * Staff, read-only: the entitlement codes an offer's limits can use (EntitlementCodeCatalogue). Read: every
 * signed-in role (like offers).
 *   GET v1/entitlement-codes            every known code
 *   GET v1/entitlement-codes?app=sales  Manage's codes (max_companies, max_users) + that app's (comma-separated list
 *                                       allowed; aliases drive / pulse / lobby / connect folded)
 * → {ok, data:{items:[{code,label,unit,app,enforced_by}], free_text_allowed:true}}
 */
class EntitlementCodesController extends LicensingBaseController
{
    public function index()
    {
        $raw = trim((string) ($this->request->getGet('app') ?? ''));
        $apps = $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));

        return $this->ok(['items' => EntitlementCodeCatalogue::all($apps), 'free_text_allowed' => true]);
    }
}
