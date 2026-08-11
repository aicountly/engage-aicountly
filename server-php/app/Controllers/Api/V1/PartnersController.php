<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Services\PartnerPortalClient;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Partner Master — Add / Edit / Delete / List screens.
 *
 * Engage stores no partner data of its own. Every call here is relayed to the
 * Partner Portal's admin API (partner.aicountly.com), which owns the single
 * copy of the Partner Master. This controller's job is: authenticate the
 * Engage superadmin (unchanged — the `jwt` route filter, same as every other
 * Engage endpoint), forward the request, audit the action locally, and relay
 * the Partner Portal's response back verbatim.
 */
class PartnersController extends BaseApiController
{
    private PartnerPortalClient $client;

    public function __construct()
    {
        $this->client = Services::partnerPortalClient();
    }

    public function index(): ResponseInterface
    {
        return $this->relay($this->client->list($this->request->getGet()));
    }

    public function show($id = null): ResponseInterface
    {
        return $this->relay($this->client->show((int) $id));
    }

    public function create(): ResponseInterface
    {
        $data   = $this->input();
        $result = $this->client->create($data);

        if (! empty($result['ok'])) {
            $this->audit('partner_create', [
                'subject_kind' => 'partner',
                'subject_id'   => $result['data']['id'] ?? null,
                'metadata'     => [
                    'email'              => $data['email']    ?? null,
                    'portal_access_set'  => ! empty($result['data']['has_portal_access']),
                    'password_generated' => ! empty($result['data']['generated_password']),
                ],
            ]);
        }

        return $this->relay($result, 201);
    }

    public function update($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $data   = $this->input();
        $result = $this->client->update($id, $data);

        if (! empty($result['ok'])) {
            $this->audit('partner_update', [
                'subject_kind' => 'partner',
                'subject_id'   => $id,
                'metadata'     => ['fields' => array_keys($data)],
            ]);
        }

        return $this->relay($result);
    }

    public function delete($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $result = $this->client->delete($id);

        if (! empty($result['ok'])) {
            $this->audit('partner_delete', ['subject_kind' => 'partner', 'subject_id' => $id]);
        }

        return $this->relay($result);
    }

    public function restore($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $result = $this->client->restore($id);

        if (! empty($result['ok'])) {
            $this->audit('partner_restore', ['subject_kind' => 'partner', 'subject_id' => $id]);
        }

        return $this->relay($result);
    }

    public function activate($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $result = $this->client->activate($id);

        if (! empty($result['ok'])) {
            $this->audit('partner_activate', ['subject_kind' => 'partner', 'subject_id' => $id]);
        }

        return $this->relay($result);
    }

    public function deactivate($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $result = $this->client->deactivate($id);

        if (! empty($result['ok'])) {
            $this->audit('partner_deactivate', ['subject_kind' => 'partner', 'subject_id' => $id]);
        }

        return $this->relay($result);
    }

    /**
     * Set or reset the partner's Partner Portal password.
     * Body: { "password": "..." } or { "generate": true }.
     */
    public function setPassword($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $data   = $this->input();
        $result = $this->client->setPassword($id, $data);

        if (! empty($result['ok'])) {
            $this->audit('partner_password_set', [
                'subject_kind' => 'partner',
                'subject_id'   => $id,
                'metadata'     => ['generated' => ! empty($data['generate'])],
            ]);
        }

        return $this->relay($result);
    }

    public function unlock($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $result = $this->client->unlock($id);

        if (! empty($result['ok'])) {
            $this->audit('partner_unlock', ['subject_kind' => 'partner', 'subject_id' => $id]);
        }

        return $this->relay($result);
    }

    /**
     * Turn a PartnerPortalClient result into the same { ok, data } / { ok,
     * error, details } envelope every other Engage endpoint returns.
     */
    private function relay(array $result, int $defaultOkStatus = 200): ResponseInterface
    {
        $status = (int) ($result['status'] ?? ($result['ok'] ? $defaultOkStatus : 502));

        if (! empty($result['ok'])) {
            return $this->ok($result['data'] ?? [], $status);
        }

        return $this->fail(
            (string) ($result['error'] ?? 'Partner Portal request failed.'),
            $status ?: 502,
            $result['details'] ?? null,
        );
    }
}
