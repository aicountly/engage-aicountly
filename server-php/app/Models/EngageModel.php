<?php

namespace App\Models;

use App\Models\Cast\TolerantJsonCast;
use CodeIgniter\Model;

/**
 * Base for Engage models with JSON columns.
 *
 * JSON fields are declared in $casts as `?json-array` (nullable column) or
 * `json-array` (NOT NULL column). Pass PHP arrays to those fields — never
 * json_encode() output; the cast encodes on write and decodes on read.
 * The `json` handler is replaced by TolerantJsonCast so rows that an older
 * release double-encoded still read back as arrays.
 */
abstract class EngageModel extends Model
{
    protected array $castHandlers = [
        'json' => TolerantJsonCast::class,
    ];

    /**
     * Fields that carry a JSON cast, for JsonColumnRepairService.
     *
     * @return list<string>
     */
    public function jsonFields(): array
    {
        $fields = [];
        foreach ($this->casts as $field => $type) {
            if (preg_match('/\A\??json(-array|\[.*\])?\z/', (string) $type)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    public function tableName(): string
    {
        return $this->table;
    }

    public function primaryKeyName(): string
    {
        return $this->primaryKey;
    }
}
