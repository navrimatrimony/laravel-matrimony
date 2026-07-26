<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The one process-level memo for "does this table / column exist?".
 *
 * This codebase defends nearly every optional table and legacy column with `Schema::hasTable()` /
 * `Schema::hasColumn()`. Each of those is an `information_schema` round trip, and they sit inside
 * per-profile and per-pair code paths — a single production suggestions request issued 1,625 of them
 * (1.2 s) purely to re-answer questions whose answer cannot change while the process is alive.
 *
 * Three ad-hoc statics already existed for exactly this ({@see \App\Models\MatrimonyProfile},
 * {@see \App\Services\Profile\ProfileCanonicalResidenceService},
 * {@see \App\Services\Matching\NearbyGeographyResolver}). Rather than grow a fourth and a fifth, they
 * now all delegate here — one memo, one flush point, per the frozen no-duplicate rule.
 *
 * The schema CAN change inside a process in exactly one situation: a test run that migrates between
 * cases. {@see flush()} is wired to Laravel's migration events in
 * {@see \App\Providers\AppServiceProvider}, and `RefreshDatabase` therefore starts every case with a
 * clean memo.
 */
final class SchemaPresence
{
    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        if (array_key_exists($key, self::$columns)) {
            return self::$columns[$key];
        }

        // A column can only exist if the table does, and the table answer is memoised — so this also
        // collapses the "hasTable + hasColumn" pairs that guard every optional column.
        return self::$columns[$key] = self::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * First column of `$columns` that exists on `$table`, else null. Encodes the very common
     * "new column when present, legacy column otherwise" fallback in a single memoised call.
     *
     * @param  list<string>  $columns
     */
    public static function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (self::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
    }
}
