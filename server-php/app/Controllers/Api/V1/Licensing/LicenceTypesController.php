<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use Config\Services;

/**
 * Staff: licence types (read: every signed-in role; write: super_admin | sales_manager).
 *   GET v1/licence-types · POST v1/licence-types {code, label, lifecycle_kind, description?, display_schema?, active?, sort_order?}
 *   PUT v1/licence-types/{id} {label?, description?, lifecycle_kind? (only while unused), display_schema?, active?, sort_order?}
 */
class LicenceTypesController extends LicensingBaseController
{
    public function index()
    {
        return $this->ok(['items' => Services::licenceTypes()->list((string) $this->request->getGet('active') === '1')]);
    }

    public function create()
    {
        return $this->result(Services::licenceTypes()->create($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->result(Services::licenceTypes()->update((int) $id, $this->input(), $this->user()));
    }
}
