<?php

namespace Tests\Unit;

use App\Models\Cast\TolerantJsonCast;
use App\Models\EngageModel;
use App\Services\JsonColumnRepairService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * JSON columns: writers used to json_encode() into fields that already have a JSON cast (stored as a
 * JSON string; reading it threw a TypeError), and non-nullable casts on nullable columns threw on
 * SQL NULL. Every model with a JSON cast now extends EngageModel (TolerantJsonCast), casts are nullable
 * where the column is, writers pass arrays, and `php spark engage:repair-json` repairs old rows.
 *
 * @internal
 */
final class JsonCastsTest extends CIUnitTestCase
{
    /** Writers that legitimately encode: query-builder writes, where no cast applies (file => fields). */
    private const BUILDER_WRITES = [
        // engage_seller_applications has no model (query builder insert): the JSONB column takes the encoded document.
        'Services/Seller/SellerApplicationService.php' => ['metadata'],
    ];

    public function testTolerantCast(): void
    {
        $this->assertSame(['a' => 1], TolerantJsonCast::get('{"a":1}', ['array']));
        $this->assertSame(['a' => 1], TolerantJsonCast::get(json_encode('{"a":1}'), ['array']), 'double-encoded reads as the document');
        $this->assertNull(TolerantJsonCast::get(null, ['array']), 'SQL NULL on a non-nullable cast');
        $this->assertNull(TolerantJsonCast::get('null', ['array']), 'JSON null');
        $this->assertSame('confirm', TolerantJsonCast::get('"confirm"', ['array']), 'a JSON string scalar passes through');
        $this->assertSame('{"a":1}', TolerantJsonCast::set(['a' => 1], ['array']));
        $this->assertSame('{"a":1}', TolerantJsonCast::set('{"a":1}', ['array']), 'pre-encoded JSON is not encoded again');
        $this->assertSame('null', TolerantJsonCast::set(null, ['array']), 'null on a non-nullable cast stays JSON null, as CodeIgniter stores it');
    }

    public function testRepairedText(): void
    {
        $this->assertSame('{"a":1}', JsonColumnRepairService::repairedText(json_encode('{"a":1}')));
        $this->assertSame('[1,2]', JsonColumnRepairService::repairedText(json_encode('[1,2]')));
        $this->assertNull(JsonColumnRepairService::repairedText('{"a":1}'), 'a document is left alone');
        $this->assertNull(JsonColumnRepairService::repairedText('"plain text"'), 'a genuine JSON string is left alone');
    }

    /** @return array<string, array<string, string>> class => [field => cast] */
    private static function jsonCasts(): array
    {
        $out = [];
        foreach (glob(APPPATH . 'Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract() || ! (new \ReflectionClass($class))->hasProperty('casts')) {
                continue;
            }
            $casts = (new \ReflectionClass($class))->getDefaultProperties()['casts'] ?? [];
            foreach ((array) $casts as $field => $type) {
                if (preg_match('/\A\??json/', (string) $type) === 1) {
                    $out[$class][$field] = (string) $type;
                }
            }
        }

        return $out;
    }

    public function testEveryModelWithAJsonCastUsesTheTolerantBase(): void
    {
        $casts = self::jsonCasts();
        $this->assertNotSame([], $casts);
        $outside = array_values(array_filter(array_keys($casts), static fn (string $c): bool => ! is_subclass_of($c, EngageModel::class)));
        $this->assertSame([], $outside);
    }

    public function testNoWriterEncodesIntoACastField(): void
    {
        $fields = [];
        foreach (self::jsonCasts() as $map) {
            $fields += array_fill_keys(array_keys($map), true);
        }
        $pattern = '/[\'"](' . implode('|', array_map('preg_quote', array_keys($fields))) . ')[\'"]\s*(?:=>|\]\s*=)\s*[^,;]*json_encode\(/';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen(APPPATH));
            if ($file->getExtension() !== 'php' || str_starts_with($rel, 'Database/') || str_starts_with($rel, 'Views/')) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match_all($pattern, $line, $m) > 0) {
                    foreach ($m[1] as $field) {
                        if (! in_array($field, self::BUILDER_WRITES[$rel] ?? [], true)) {
                            $hits[] = "{$rel}:" . ($n + 1) . " {$field}";
                        }
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'pass the array: the model\'s JSON cast encodes it');
    }
}
