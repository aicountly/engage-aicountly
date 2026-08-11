<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Partner Master. Engage owns every write to this table; the Partner Portal
 * only reads it (plus its own last_login_* / failed_attempts bookkeeping).
 */
class PartnersModel extends Model
{
    protected $table          = 'engage_partners';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $useSoftDeletes = true;
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'partner_uid', 'name', 'contact_name', 'email', 'phone', 'partner_type',
        'website', 'country', 'city',
        'password_hash', 'password_set_at', 'status',
        'account_id', 'owner_id',
        'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until',
        'notes', 'metadata',
    ];

    /** Columns that must never leave the API. */
    public const HIDDEN_FIELDS = ['password_hash'];

    public function findByEmail(string $email): ?array
    {
        return $this->where('LOWER(email)', strtolower(trim($email)))->first();
    }

    /**
     * Is this email already taken by a live partner (optionally excluding one id)?
     */
    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $qb = $this->where('LOWER(email)', strtolower(trim($email)));
        if ($exceptId !== null) {
            $qb->where('id !=', $exceptId);
        }

        return $qb->countAllResults() > 0;
    }

    /**
     * Strip credential columns before a row is returned over the API.
     */
    public static function publicRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach (self::HIDDEN_FIELDS as $field) {
            unset($row[$field]);
        }

        $row['has_portal_access'] = ! empty($row['password_set_at']);

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publicRows(array $rows): array
    {
        return array_map(static fn (array $r) => self::publicRow($r), $rows);
    }

    public static function newPartnerUid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
