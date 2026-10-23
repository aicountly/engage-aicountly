<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductsModel extends Model
{
    protected $table         = 'engage_products';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['code', 'name', 'category', 'description', 'is_active', 'sort_order'];

    /**
     * The product a free-text interest names, or null: its code or its display name, case-insensitive,
     * surrounding / repeated spaces ignored ("edge", "EDGE", "Aicountly Edge" → `edge`). A code match wins
     * over a name match. Used by every lead intake (Reach, seller registration) and by payment confirmation.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(?string $text): ?array
    {
        $needle = strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));
        if ($needle === '') {
            return null;
        }

        return $this->where('LOWER(code)', $needle)->first()
            ?? $this->where('LOWER(name)', $needle)->first();
    }
}
