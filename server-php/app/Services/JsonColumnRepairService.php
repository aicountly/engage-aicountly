<?php

namespace App\Services;

use App\Models\EngageModel;
use App\Models\Cast\TolerantJsonCast;
use CodeIgniter\Database\BaseConnection;
use JsonException;

/**
 * Unwraps JSONB values that an older release stored double-encoded: the
 * column holds a JSON string whose text is the real object/array (or, for
 * scalar values, a quoted string). Backs `php spark engage:repair-json`.
 *
 * Idempotent: only rows where jsonb_typeof(column) = 'string' AND the string
 * is itself JSON text ({...}, [...] or "...") are touched, and a repaired row
 * no longer matches. Each UPDATE also re-checks the value it read, so a row
 * changed concurrently is skipped rather than overwritten. Reads are already
 * tolerant (TolerantJsonCast), so running this is about clean data for SQL
 * and reports, not about keeping the API up.
 */
class JsonColumnRepairService
{
    private const BATCH = 500;

    /**
     * Every concrete EngageModel under app/Models.
     *
     * @return list<class-string<EngageModel>>
     */
    public static function models(): array
    {
        $out = [];
        foreach (glob(APPPATH . 'Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, EngageModel::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $out[] = $class;
            }
        }
        sort($out);

        return $out;
    }

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return list<array{table: string, column: string, pk: string, type: string}>
     */
    public function columns(?string $onlyTable = null): array
    {
        $out = [];
        foreach (self::models() as $class) {
            $model = new $class($this->connection());
            if ($onlyTable !== null && $onlyTable !== '' && $model->tableName() !== $onlyTable) {
                continue;
            }
            foreach ($model->jsonFields() as $field) {
                $type = $this->connection()->query(
                    'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                    [$model->tableName(), $field],
                )->getRow()->data_type ?? null;
                if ($type !== 'jsonb' && $type !== 'json') {
                    continue; // not a JSON column (e.g. text[]) — nothing to unwrap in SQL
                }
                $out[] = ['table' => $model->tableName(), 'column' => $field, 'pk' => $model->primaryKeyName(), 'type' => $type];
            }
        }

        return $out;
    }

    /**
     * @return list<array{table: string, column: string, candidates: int, repaired: int, skipped: int}>
     */
    public function run(bool $apply, ?string $onlyTable = null): array
    {
        $db      = $this->connection();
        $results = [];

        foreach ($this->columns($onlyTable) as $col) {
            $table  = $db->escapeIdentifiers($col['table']);
            $column = $db->escapeIdentifiers($col['column']);
            $pk     = $db->escapeIdentifiers($col['pk']);

            $stats  = ['table' => $col['table'], 'column' => $col['column'], 'candidates' => 0, 'repaired' => 0, 'skipped' => 0];
            $lastId = 0;

            while (true) {
                $rows = $db->query(
                    "SELECT {$pk} AS id, {$column}::text AS raw FROM {$table}
                      WHERE {$col['type']}_typeof({$column}) = 'string' AND {$pk} > ?
                      ORDER BY {$pk} LIMIT " . self::BATCH,
                    [$lastId],
                )->getResultArray();

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $fixed  = self::repairedText((string) $row['raw']);
                    if ($fixed === null) {
                        continue; // a genuine JSON string value, not double-encoded
                    }

                    $stats['candidates']++;
                    if (! $apply) {
                        continue;
                    }

                    $db->query(
                        "UPDATE {$table} SET {$column} = CAST(? AS {$col['type']}) WHERE {$pk} = ? AND {$column}::text = ?",
                        [$fixed, $lastId, $row['raw']],
                    );
                    $db->affectedRows() === 1 ? $stats['repaired']++ : $stats['skipped']++;
                }
            }

            $results[] = $stats;
        }

        return $results;
    }

    /**
     * The JSON text a double-encoded stored value should have been, or null
     * when the stored value is not double-encoded.
     *
     * @param string $storedText the column as JSON text (e.g. "\"{\\\"a\\\":1}\"")
     */
    public static function repairedText(string $storedText): ?string
    {
        try {
            $value = json_decode($storedText, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_string($value) || ! TolerantJsonCast::looksEncoded($value)) {
            return null;
        }

        $unwrapped = TolerantJsonCast::unwrap($value, false);
        if ($unwrapped === $value) {
            return null; // looked like JSON but did not parse
        }

        // Decoded as objects (not associative arrays) so {} stays {} rather than [].
        return json_encode($unwrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function connection(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
