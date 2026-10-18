<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use Config\Services;

/**
 * Staff: account ↔ Manage company mapping (read: every signed-in role; write: super_admin | sales_manager).
 *   GET  v1/account-companies ?account_id=&cmp_id=&status=
 *   POST v1/account-companies {account_id, cmp_id, company_name?, verification_note}   (recorded "verified manually by <staff>")
 *   POST v1/account-companies/{id}/unlink {reason}
 */
class AccountCompaniesController extends LicensingBaseController
{
    public function index()
    {
        $q = $this->request->getGet();

        return $this->ok(['items' => Services::accountCompanies()->list(is_array($q) ? $q : [])]);
    }

    public function create()
    {
        return $this->result(Services::accountCompanies()->link($this->input(), $this->user()));
    }

    public function unlink($id = null)
    {
        return $this->result(Services::accountCompanies()->unlink((int) $id, (string) ($this->input()['reason'] ?? ''), $this->user()));
    }
}
