<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Libraries\Seller\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Licence types (engage_licence_types) — a lookup staff maintain; nothing is shipped or hard-coded.
 * lifecycle_kind (paid | trial | complimentary) is the only fixed vocabulary: it is what Manage's subscription
 * kind understands. display_schema is free JSON describing how a type may be presented (Manage renders it as given).
 * code is permanent; lifecycle_kind can change only while no offer uses the type.
 */
class LicenceTypeService
{
    public const KINDS = ['paid', 'trial', 'complimentary'];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function view(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'code'           => $r['code'],
            'label'          => $r['label'],
            'description'    => $r['description'],
            'lifecycle_kind' => $r['lifecycle_kind'],
            'display_schema' => (object) S::json($r['display_schema']),
            'active'         => in_array($r['active'], [true, 't', 'true', 1, '1'], true),
            'sort_order'     => (int) $r['sort_order'],
            'offers_count'   => isset($r['offers_count']) ? (int) $r['offers_count'] : null,
            'created_at'     => S::iso($r['created_at']),
            'updated_at'     => S::iso($r['updated_at']),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(bool $activeOnly = false): array
    {
        $sql = 'SELECT t.*, (SELECT COUNT(*) FROM engage_offers o WHERE o.licence_type_code = t.code) AS offers_count FROM engage_licence_types t';
        if ($activeOnly) {
            $sql .= ' WHERE t.active';
        }

        return array_map([self::class, 'view'], $this->db()->query($sql . ' ORDER BY t.sort_order, t.label, t.id')->getResultArray());
    }

    public function find(int $id): ?array
    {
        return $this->db()->table('engage_licence_types')->where('id', $id)->get()->getRowArray() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        return $this->db()->table('engage_licence_types')->where('code', $code)->get()->getRowArray() ?: null;
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function fields(array $in, bool $creating): array
    {
        $e = [];
        $out = [];
        if ($creating) {
            $code = strtolower(trim((string) ($in['code'] ?? '')));
            if (! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,47}\z/', $code)) {
                $e['code'] = 'code: 2–48 lowercase letters, digits, - or _.';
            }
            $out['code'] = $code;
        }
        if ($creating || array_key_exists('label', $in)) {
            $label = trim((string) ($in['label'] ?? ''));
            if (mb_strlen($label) < 2 || mb_strlen($label) > 120) {
                $e['label'] = 'Give the licence type a label (2–120 characters).';
            }
            $out['label'] = $label;
        }
        if ($creating || array_key_exists('lifecycle_kind', $in)) {
            $kind = (string) ($in['lifecycle_kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                $e['lifecycle_kind'] = 'lifecycle_kind must be one of: ' . implode(', ', self::KINDS) . '.';
            }
            $out['lifecycle_kind'] = $kind;
        }
        if (array_key_exists('description', $in)) {
            $out['description'] = trim((string) $in['description']) ?: null;
        }
        if ($creating || array_key_exists('display_schema', $in)) {
            $ds = $in['display_schema'] ?? [];
            if (is_string($ds)) {
                $ds = $ds === '' ? [] : json_decode($ds, true);
            }
            if (! is_array($ds) || ($ds !== [] && array_is_list($ds))) {
                $e['display_schema'] = 'display_schema must be a JSON object.';
                $ds = [];
            } elseif (strlen((string) json_encode($ds)) > 16384) {
                $e['display_schema'] = 'display_schema is too large (16 KB maximum).';
            }
            $out['display_schema'] = json_encode((object) $ds);
        }
        if (array_key_exists('active', $in)) {
            $out['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 't' : 'f';
        }
        if (array_key_exists('sort_order', $in)) {
            if (! is_numeric($in['sort_order']) || (int) $in['sort_order'] != $in['sort_order']) {
                $e['sort_order'] = 'sort_order must be a whole number.';
            }
            $out['sort_order'] = (int) $in['sort_order'];
        }

        return [$out, $e];
    }

    public function create(array $in, array $staff): array
    {
        [$f, $e] = $this->fields($in, true);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the licence type.', 'details' => $e];
        }
        if ($this->findByCode($f['code'])) {
            return ['status' => 409, 'error' => "Licence type {$f['code']} already exists."];
        }
        $f['created_by'] = (int) $staff['id'];
        $this->db()->table('engage_licence_types')->insert($f);
        $id = (int) $this->db()->insertID();
        $this->audit('licence_type.created', $id, ['code' => $f['code'], 'lifecycle_kind' => $f['lifecycle_kind']]);

        return ['status' => 201, 'data' => self::view($this->find($id))];
    }

    public function update(int $id, array $in, array $staff): array
    {
        $t = $this->find($id);
        if (! $t) {
            return ['status' => 404, 'error' => 'Licence type not found.'];
        }
        if (array_key_exists('code', $in) && (string) $in['code'] !== $t['code']) {
            return ['status' => 422, 'error' => 'A licence type code cannot change.', 'details' => ['code' => 'Create a new licence type instead.']];
        }
        [$f, $e] = $this->fields($in, false);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the licence type.', 'details' => $e];
        }
        if (isset($f['lifecycle_kind']) && $f['lifecycle_kind'] !== $t['lifecycle_kind']) {
            $used = $this->db()->table('engage_offers')->where('licence_type_code', $t['code'])->countAllResults();
            if ($used > 0) {
                return ['status' => 409, 'error' => 'Offers already use this licence type, so its lifecycle kind cannot change. Create a new licence type.'];
            }
        }
        if ($f !== []) {
            $f['updated_at'] = Clock::now();
            $this->db()->table('engage_licence_types')->where('id', $id)->update($f);
        }
        $this->audit('licence_type.updated', $id, ['code' => $t['code'], 'fields' => array_keys($f)]);

        return ['status' => 200, 'data' => self::view($this->find($id))];
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'licence_type', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}
