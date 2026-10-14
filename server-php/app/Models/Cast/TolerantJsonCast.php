<?php

namespace App\Models\Cast;

use CodeIgniter\DataCaster\Cast\BaseCast;
use CodeIgniter\DataCaster\Exceptions\CastException;
use JsonException;
use stdClass;

/**
 * The `json` cast handler of every Engage model with a JSON column (EngageModel::$castHandlers;
 * same as build-aicountly's, which met the same bug first).
 *
 * Reads are tolerant. Writers used to json_encode() a value and hand the string to a field that
 * already had a JSON cast, so the cast encoded it a second time and the column holds a JSON
 * *string* whose text is the real document. CodeIgniter's JsonCast::get() then returns that
 * string where it promises array|stdClass, and the request dies with a TypeError. get() here
 * unwraps such string-wrapped JSON (a few levels at most), returns null for SQL NULL / JSON null
 * (CodeIgniter's handler throws on a non-nullable cast), and passes JSON scalars through.
 *
 * Writes encode once. A `json-array` field handed a string that is itself a JSON object/array is
 * stored as that document (and logged) rather than encoded again. null on a non-nullable cast is
 * stored as JSON null, as CodeIgniter does (a nullable cast never reaches the handler with null).
 *
 * `php spark engage:repair-json` rewrites rows already stored double-encoded (JsonColumnRepairService).
 */
final class TolerantJsonCast extends BaseCast
{
    /** How many layers of string wrapping get() will peel off. */
    public const MAX_UNWRAP = 4;

    private const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function get(mixed $value, array $params = [], ?object $helper = null): mixed
    {
        if ($value === null) {
            return null;
        }

        $associative = in_array('array', $params, true);

        // Already decoded (e.g. a row passed through twice): nothing to do.
        if (is_array($value) || $value instanceof stdClass) {
            return $value;
        }

        if (! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        try {
            $decoded = json_decode($value, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CastException::forInvalidJsonFormat($e->getCode());
        }

        return self::unwrap($decoded, $associative);
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): mixed
    {
        if ($value === null) {
            return 'null'; // non-nullable cast: CodeIgniter's JsonCast stores JSON null too
        }

        if (is_string($value) && in_array('array', $params, true)) {
            $unwrapped = self::unwrap($value, false);
            if (is_array($unwrapped) || $unwrapped instanceof stdClass) {
                log_message('warning', 'A json-array field was given pre-encoded JSON; storing the decoded document. Pass the array instead of json_encode() output.');
                $value = $unwrapped;
            }
        }

        try {
            return json_encode($value, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw CastException::forInvalidJsonFormat($e->getCode());
        }
    }

    /**
     * Peels string layers off a decoded JSON value while the string is
     * itself JSON text (an object, an array or a quoted string).
     */
    public static function unwrap(mixed $decoded, bool $associative): mixed
    {
        for ($i = 0; $i < self::MAX_UNWRAP && is_string($decoded) && self::looksEncoded($decoded); $i++) {
            try {
                $decoded = json_decode($decoded, $associative, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                break;
            }
        }

        return $decoded;
    }

    /**
     * True when $text is shaped like JSON text that a writer encoded again:
     * `{...}`, `[...]` or `"..."`. Bare numbers, booleans and null are left
     * alone — a stored string "5" or "true" is ambiguous, and those are
     * legitimate setting values.
     */
    public static function looksEncoded(string $text): bool
    {
        $text = trim($text);
        if (strlen($text) < 2) {
            return false;
        }

        $first = $text[0];
        $last  = $text[strlen($text) - 1];

        return ($first === '{' && $last === '}')
            || ($first === '[' && $last === ']')
            || ($first === '"' && $last === '"');
    }
}
