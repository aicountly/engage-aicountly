<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use Config\Services;

/**
 * Staff: licences (read: every signed-in role; write: super_admin | sales_manager). Every write carries the
 * licence `version` it changes (optimistic lock; 409 version_conflict when stale).
 *   GET  v1/licences ?account_id=&cmp_id=&status=&q=          GET v1/licences/{ref}  (with events + seat assignments)
 *   POST v1/licences {account_id, cmp_id, offer_code, offer_version?, starts_at?, activate?, seats_total?, invoice_refs?, notes?}
 *   POST v1/licences/{ref}/activate | suspend | reinstate | revoke   {version, reason (suspend/reinstate/revoke)}
 *   POST v1/licences/{ref}/renew   {version, offer_version?, invoice_refs?, reason?}
 *   POST v1/licences/{ref}/extend  {version, ends_at, reason}
 *   POST v1/licences/{ref}/seats   {version, seats_total|null, reason?}
 *   POST v1/licences/{ref}/seat-assignments {version, member_ref?, member_label?}
 *   POST v1/licences/{ref}/seat-assignments/{id}/release {version}
 *   POST v1/licences/{ref}/invoice-refs {version, invoice_ref}
 */
class LicencesController extends LicensingBaseController
{
    public function index()
    {
        $q = $this->request->getGet();

        return $this->ok(['items' => Services::licences()->listForStaff(is_array($q) ? $q : [])]);
    }

    public function show($ref = null)
    {
        $d = Services::licences()->staffDetail((string) $ref);

        return $d ? $this->ok($d) : $this->fail('Licence not found.', 404);
    }

    public function create()
    {
        return $this->result(Services::licences()->issue($this->input(), $this->user()));
    }

    public function activate($ref = null)
    {
        return $this->result(Services::licences()->activate((string) $ref, $this->input(), $this->user()));
    }

    public function suspend($ref = null)
    {
        return $this->result(Services::licences()->suspend((string) $ref, $this->input(), $this->user()));
    }

    public function reinstate($ref = null)
    {
        return $this->result(Services::licences()->reinstate((string) $ref, $this->input(), $this->user()));
    }

    public function revoke($ref = null)
    {
        return $this->result(Services::licences()->revoke((string) $ref, $this->input(), $this->user()));
    }

    public function renew($ref = null)
    {
        return $this->result(Services::licences()->renew((string) $ref, $this->input(), $this->user()));
    }

    public function extend($ref = null)
    {
        return $this->result(Services::licences()->extend((string) $ref, $this->input(), $this->user()));
    }

    public function seats($ref = null)
    {
        return $this->result(Services::licences()->changeSeats((string) $ref, $this->input(), $this->user()));
    }

    public function assignSeat($ref = null)
    {
        return $this->result(Services::licences()->assignSeat((string) $ref, $this->input(), $this->user()));
    }

    public function releaseSeat($ref = null, $seatId = null)
    {
        return $this->result(Services::licences()->releaseSeat((string) $ref, (int) $seatId, $this->input(), $this->user()));
    }

    public function invoiceRef($ref = null)
    {
        return $this->result(Services::licences()->linkInvoice((string) $ref, $this->input(), $this->user()));
    }
}
