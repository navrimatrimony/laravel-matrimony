<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Admin UI: fill *_mr cells that the data engine reports as pending, with safe identifier validation
 * and optional duplicate Marathi text checks under the same parent (e.g. addresses.parent_id).
 */
class MrLocalizationFillService
{
    private const IDENT_RX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @return array{table: string, base: string, mr: string}
     */
    public function assertValidPair(string $table, string $base, string $mr): array
    {
        foreach ([$table, $base, $mr] as $ident) {
            if (! is_string($ident) || $ident === '' || ! preg_match(self::IDENT_RX, $ident)) {
                throw new InvalidArgumentException('Invalid table or column name.');
            }
        }
        if (! str_ends_with($mr, '_mr')) {
            throw new InvalidArgumentException('Marathi column must end with _mr.');
        }
        if ($base !== substr($mr, 0, -3)) {
            throw new InvalidArgumentException('Base column does not match the _mr column pair.');
        }
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, [$base, $mr])) {
            throw new InvalidArgumentException('Table or columns are not available.');
        }
        if (! $this->tableHasPrimaryId($table)) {
            throw new InvalidArgumentException('This table has no id column; manual fill is not supported here.');
        }

        return ['table' => $table, 'base' => $base, 'mr' => $mr];
    }

    public function tableHasPrimaryId(string $table): bool
    {
        return Schema::hasColumn($table, 'id');
    }

    /**
     * @return list<array{table: string, base_column: string, mr_column: string}>
     */
    public function discoverMrColumnPairs(): array
    {
        $out = [];
        foreach ($this->listBaseTableNames() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $col) {
                if (! str_ends_with((string) $col, '_mr')) {
                    continue;
                }
                $mr = (string) $col;
                $base = substr($mr, 0, -3);
                if ($base === '' || ! Schema::hasColumn($table, $base) || ! $this->tableHasPrimaryId($table)) {
                    continue;
                }
                $out[] = ['table' => $table, 'base_column' => $base, 'mr_column' => $mr];
            }
        }

        return $out;
    }

    /**
     * Live DB snapshot: same rules as python {@code mr_localization_engine.analyze} (expected / filled / pending per *_mr pair).
     *
     * @return array{summary: array<string, int>, columns: list<array<string, mixed>>, fix: array<string, mixed>, source: string}
     */
    public function buildLiveLocalizationReport(): array
    {
        $details = [];
        $totalPending = 0;
        $totalExpected = 0;
        $totalFilled = 0;

        foreach ($this->discoverMrColumnPairs() as $pair) {
            try {
                $counts = $this->countsForPair($pair['table'], $pair['base_column'], $pair['mr_column']);
            } catch (\Throwable) {
                continue;
            }
            $totalExpected += $counts['expected'];
            $totalFilled += $counts['filled'];
            $totalPending += $counts['pending'];
            $details[] = [
                'table' => $pair['table'],
                'base_column' => $pair['base_column'],
                'mr_column' => $pair['mr_column'],
                'expected_rows' => $counts['expected'],
                'filled_rows' => $counts['filled'],
                'pending_rows' => $counts['pending'],
            ];
        }

        usort($details, fn ($a, $b) => ((int) ($b['pending_rows'] ?? 0)) <=> ((int) ($a['pending_rows'] ?? 0)));

        return [
            'summary' => [
                'mr_columns_found' => count($details),
                'pending_rows_total' => $totalPending,
                'expected_rows_total' => $totalExpected,
                'filled_rows_total' => $totalFilled,
            ],
            'columns' => $details,
            'fix' => [
                'updated_rows' => 0,
                'updated_by_column' => [],
                'skipped' => [],
            ],
            'source' => 'database_live',
        ];
    }

    /**
     * @return list<string>
     */
    protected function listBaseTableNames(): array
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT GLOB 'sqlite*'"))
                ->pluck('name')
                ->map(fn ($n) => (string) $n)
                ->values()
                ->all();
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $db = $connection->getDatabaseName();

            return collect(DB::select(
                'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$db, 'BASE TABLE']
            ))
                ->pluck('n')
                ->map(fn ($n) => (string) $n)
                ->values()
                ->all();
        }

        try {
            return collect(DB::select('SHOW TABLES'))->map(function ($row): ?string {
                $arr = (array) $row;

                return (string) reset($arr);
            })->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{expected: int, filled: int, pending: int}
     */
    public function countsForPair(string $table, string $base, string $mr): array
    {
        $t = $this->assertValidPair($table, $base, $mr);
        $b = $t['base'];
        $m = $t['mr'];
        $tbl = $t['table'];

        $sql = 'SELECT '
            .'SUM(CASE WHEN `'.$b.'` IS NOT NULL AND TRIM(`'.$b.'`) <> \'\' THEN 1 ELSE 0 END) AS expected, '
            .'SUM(CASE WHEN `'.$b.'` IS NOT NULL AND TRIM(`'.$b.'`) <> \'\' '
            .'AND `'.$m.'` IS NOT NULL AND TRIM(`'.$m.'`) <> \'\' THEN 1 ELSE 0 END) AS filled, '
            .'SUM(CASE WHEN `'.$b.'` IS NOT NULL AND TRIM(`'.$b.'`) <> \'\' '
            .'AND (`'.$m.'` IS NULL OR TRIM(`'.$m.'`) = \'\') THEN 1 ELSE 0 END) AS pending '
            .'FROM `'.$tbl.'`';

        $row = DB::selectOne($sql);

        return [
            'expected' => (int) ($row->expected ?? 0),
            'filled' => (int) ($row->filled ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    public function pendingQuery(string $table, string $base, string $mr): Builder
    {
        $t = $this->assertValidPair($table, $base, $mr);

        return DB::table($t['table'])
            ->select(array_filter([
                'id',
                $t['base'],
                $t['mr'],
                Schema::hasColumn($t['table'], 'parent_id') ? 'parent_id' : null,
                Schema::hasColumn($t['table'], 'type') ? 'type' : null,
            ]))
            ->whereNotNull($t['base'])
            ->whereRaw('TRIM(`'.$t['base'].'`) <> ?', [''])
            ->where(function (Builder $q) use ($t): void {
                $q->whereNull($t['mr'])->orWhereRaw('TRIM(`'.$t['mr'].'`) = ?', ['']);
            })
            ->orderBy('id');
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    public function tryUpdate(
        string $table,
        string $base,
        string $mr,
        int $rowId,
        string $marathi,
    ): array {
        $t = $this->assertValidPair($table, $base, $mr);
        $marathi = trim($marathi);
        if ($marathi === '') {
            return ['ok' => false, 'message' => 'Marathi text is required.'];
        }

        $row = DB::table($t['table'])->where('id', $rowId)->first();
        if ($row === null) {
            return ['ok' => false, 'message' => 'Row not found.'];
        }

        if (property_exists($row, $t['base'])
            && $row->{$t['base']} !== null
            && trim((string) $row->{$t['base']}) === '') {
            return ['ok' => false, 'message' => 'This row has no base text; nothing to translate.'];
        }

        if ($this->hasDuplicateMr($t['table'], $t['mr'], $rowId, $marathi, $row)) {
            return [
                'ok' => false,
                'message' => 'Another row under the same parent already uses this Marathi value. Use a distinct spelling or fix the other row first.',
            ];
        }

        DB::table($t['table'])->where('id', $rowId)->update([$t['mr'] => $marathi]);

        return ['ok' => true, 'message' => null];
    }

    /**
     * @param  object  $row  Current row (stdClass)
     */
    public function hasDuplicateMr(string $table, string $mrColumn, int $rowId, string $trimmedMr, object $row): bool
    {
        if (! Schema::hasColumn($table, 'parent_id')) {
            return false;
        }

        $q = DB::table($table)
            ->where('id', '!=', $rowId)
            ->whereNotNull($mrColumn)
            ->whereRaw('TRIM(`'.$mrColumn.'`) = ?', [$trimmedMr]);

        $pid = property_exists($row, 'parent_id') ? $row->parent_id : null;
        if ($pid === null) {
            $q->whereNull('parent_id');
        } else {
            $q->where('parent_id', $pid);
        }

        return $q->exists();
    }
}
