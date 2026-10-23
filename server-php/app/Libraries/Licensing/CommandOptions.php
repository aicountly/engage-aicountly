<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

use CodeIgniter\CLI\CLI;

/**
 * Options of a spark command, from the $params CodeIgniter passes (command() / tests) and the real argv. Accepts both
 * `--holder value` (CodeIgniter's own form) and `--holder=value` (the form the docs and contract use, which
 * CodeIgniter would otherwise read as an option literally named "holder=value").
 */
trait CommandOptions
{
    /** null = absent; true = a flag without value; else the value. */
    protected static function opt(array $params, string $name): mixed
    {
        foreach ([$params, CLI::getOptions()] as $source) {
            if (array_key_exists($name, $source)) {
                return $source[$name] ?? true;
            }
            foreach ($source as $k => $v) {
                if (is_string($k) && str_starts_with($k, $name . '=')) {
                    return substr($k, strlen($name) + 1);
                }
            }
        }

        return null;
    }
}
