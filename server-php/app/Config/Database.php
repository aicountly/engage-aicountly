<?php

namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    public array $default = [
        'DSN'      => '',
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => '',
        'DBDriver' => 'Postgre',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'charset'  => 'utf8',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 5432,
        'schema'   => 'public',
    ];

    public array $tests = [
        'DSN'      => '',
        'hostname' => '127.0.0.1',
        'username' => '',
        'password' => '',
        'database' => '',
        'DBDriver' => 'Postgre',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'charset'  => 'utf8',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 5432,
        'schema'   => 'public',
    ];

    public function __construct()
    {
        parent::__construct();

        // DB creds come only from .env. Nothing is hardcoded.
        $this->default['hostname'] = env('ENGAGE_DB_HOST', '127.0.0.1');
        $this->default['port']     = (int) env('ENGAGE_DB_PORT', '5432');
        $this->default['database'] = env('ENGAGE_DB_NAME', '');
        $this->default['username'] = env('ENGAGE_DB_USER', '');
        $this->default['password'] = env('ENGAGE_DB_PASSWORD', '');

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
