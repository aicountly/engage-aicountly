<?php

namespace Config;

use App\Filters\ConsolePortalFilter;
use App\Filters\CorsFilter;
use App\Filters\InternalTokenFilter;
use App\Filters\JwtFilter;
use App\Filters\RoleFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf'           => CSRF::class,
        'toolbar'        => DebugToolbar::class,
        'honeypot'       => Honeypot::class,
        'invalidchars'   => InvalidChars::class,
        'secureheaders'  => SecureHeaders::class,
        'forcehttps'     => ForceHTTPS::class,
        'pagecache'      => PageCache::class,
        'performance'    => PerformanceMetrics::class,
        'cors'           => CorsFilter::class,
        'jwt'            => JwtFilter::class,
        'role'           => RoleFilter::class,
        'console-portal' => ConsolePortalFilter::class,
        'internal-token' => InternalTokenFilter::class,
    ];

    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            'toolbar',
        ],
    ];

    public array $globals = [
        'before' => [
            'cors',
        ],
        'after' => [
            'cors',
            'secureheaders',
        ],
    ];

    public array $methods = [];

    public array $filters = [];
}
