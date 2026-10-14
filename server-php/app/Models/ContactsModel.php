<?php

namespace App\Models;

class ContactsModel extends EngageModel
{
    protected $table         = 'engage_contacts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'account_id', 'name', 'title', 'email', 'mobile', 'whatsapp',
        'is_primary', 'notes', 'metadata',
    ];

    protected array $casts = ['metadata' => '?json-array'];

    public function findByEmail(string $email): ?array
    {
        if ($email === '') return null;
        return $this->where('email', $email)->first();
    }
}
