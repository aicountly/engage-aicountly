<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingsModel extends Model
{
    protected $table         = 'engage_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['key', 'value_json', 'description', 'updated_by'];

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $row = $this->where('key', $key)->first();
        if (! $row) {
            return $default;
        }
        $val = $this->decodeValue($row['value_json'] ?? null);
        return $val ?? $default;
    }

    public function setSetting(string $key, mixed $value, ?int $userId = null): void
    {
        $encoded = json_encode($value);
        $row = $this->where('key', $key)->first();
        if ($row) {
            $this->update($row['id'], ['value_json' => $encoded, 'updated_by' => $userId]);
            return;
        }
        $this->insert([
            'key'        => $key,
            'value_json' => $encoded,
            'updated_by' => $userId,
        ]);
    }

    public function all(): array
    {
        $rows = $this->orderBy('key')->findAll();
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $this->decodeValue($r['value_json'] ?? null);
        }
        return $out;
    }

    private function decodeValue(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw) || is_bool($raw) || is_int($raw) || is_float($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }

        return $decoded;
    }
}
