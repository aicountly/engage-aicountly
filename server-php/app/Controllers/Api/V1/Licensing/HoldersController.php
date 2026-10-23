<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\LicenceService;
use Config\Database;
use Config\Services;

/**
 * Staff: the holder directory (engage_holders — my.aicountly users Engage heard of through Basic provisioning, or
 * that staff issued a licence to). Read: every signed-in role.
 *   GET v1/holders ?q=      search by e-mail, name or uuid (20 rows)
 *   GET v1/holders/{uuid}   the holder + their licences + their latest entitlement pushes
 */
class HoldersController extends LicensingBaseController
{
    public function index()
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $db = Database::connect();
        $sql = 'SELECT h.*, (SELECT COUNT(*) FROM engage_licences l WHERE l.holder_uuid = h.holder_uuid) AS licences_count FROM engage_holders h';
        $binds = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $sql .= ' WHERE h.holder_uuid = ? OR LOWER(h.email) LIKE LOWER(?) OR h.name ILIKE ?';
            $binds = [$q, $like, $like];
        }
        $rows = $db->query($sql . ' ORDER BY h.updated_at DESC LIMIT 20', $binds)->getResultArray();

        return $this->ok(['items' => array_map([self::class, 'view'], $rows)]);
    }

    public function show($uuid = null)
    {
        $uuid = (string) $uuid;
        if (! LicenceService::validHolder($uuid)) {
            return $this->fail('Holder not found.', 404);
        }
        $row = Database::connect()->query('SELECT h.*, 0 AS licences_count FROM engage_holders h WHERE h.holder_uuid = ?', [$uuid])->getRowArray();
        $licences = Services::licences()->listForStaff(['holder_uuid' => $uuid]);
        if (! $row && $licences === []) {
            return $this->fail('Holder not found.', 404);
        }

        return $this->ok(($row ? self::view($row) : ['holder_uuid' => $uuid]) + [
            'licences'     => $licences,
            'entitlements' => Services::entitlementOutbox()->list(['holder_uuid' => $uuid, 'limit' => 50]),
        ]);
    }

    public static function view(array $h): array
    {
        return [
            'holder_uuid'         => $h['holder_uuid'],
            'email'               => $h['email'],
            'name'                => $h['name'],
            'source'              => $h['source'],
            'signed_up_at'        => S::iso($h['signed_up_at']),
            'last_provisioned_at' => S::iso($h['last_provisioned_at']),
            'licences_count'      => (int) ($h['licences_count'] ?? 0),
        ];
    }
}
