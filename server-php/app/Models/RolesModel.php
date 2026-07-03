<?php

namespace App\Models;

use CodeIgniter\Model;

class RolesModel extends Model
{
    protected $table         = 'engage_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['code', 'name', 'description'];

    public function findByCode(string $code): ?array
    {
        return $this->where('code', $code)->first();
    }
}
