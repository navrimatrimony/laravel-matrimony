<?php

namespace App\Console\Commands;

use App\Support\Encoding\Utf8MojibakeRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RepairUtf8MojibakeCommand extends Command
{
    protected $signature = 'db:repair-utf8-mojibake
        {--apply : Persist repairs (default is dry-run)}
        {--force : Allow --apply outside local APP_ENV}
        {--table= : Limit to one table}
        {--column= : Limit to one column (requires --table)}
        {--chunk=500 : Rows per chunk}
        {--limit=0 : Max rows to repair across all columns (0 = no limit)}
        {--sample=3 : Sample repairs to print per column}';

    protected $description = 'Repair local Windows-1252/UTF-8 mojibake in utf8mb4 text columns (dry-run by default)';

    /**
     * Transient / unsafe columns that must never be rewritten by this tool.
     *
     * @var list<string>
     */
    private array $skipExact = [
        'cache.value',
        'cache_locks.owner',
        'jobs.payload',
        'failed_jobs.payload',
        'failed_jobs.exception',
        'sessions.payload',
        'password_reset_tokens.token',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $env = (string) config('app.env');

        if ($apply && $env !== 'local' && ! $force) {
            $this->error('Refusing --apply outside APP_ENV=local without --force (production must stay untouched).');

            return self::FAILURE;
        }

        $tableFilter = $this->option('table');
        $columnFilter = $this->option('column');
        if ($columnFilter && ! $tableFilter) {
            $this->error('--column requires --table.');

            return self::FAILURE;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $sampleN = max(0, (int) $this->option('sample'));

        $columns = $this->discoverColumns(
            is_string($tableFilter) ? $tableFilter : null,
            is_string($columnFilter) ? $columnFilter : null,
        );

        if ($columns === []) {
            $this->warn('No matching utf8mb4 text columns found.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN')." mode | env={$env} | columns=".count($columns));

        $scanned = 0;
        $candidates = 0;
        $repaired = 0;
        $failed = 0;
        $remaining = $limit > 0 ? $limit : PHP_INT_MAX;

        foreach ($columns as $columnMeta) {
            if ($remaining <= 0) {
                break;
            }

            $table = $columnMeta['table'];
            $column = $columnMeta['column'];
            $qualified = $table.'.'.$column;

            if (in_array($qualified, $this->skipExact, true)) {
                $this->line("skip {$qualified} (protected)");

                continue;
            }

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $pk = $this->primaryKey($table);
            if ($pk === null) {
                $this->warn("skip {$qualified} (no single-column primary key)");

                continue;
            }

            $columnCandidates = 0;
            $columnRepaired = 0;
            $samplesPrinted = 0;

            try {
                $query = DB::table($table)
                    ->select([$pk, $column])
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->where(function ($q) use ($column) {
                        // Broad Indic mojibake markers (Devanagari…Malayalam) + triple-encoding residue.
                        $q->where($column, 'regexp', "à[¤-µ]")
                            ->orWhere($column, 'like', '%ÃÂ¤%')
                            ->orWhere($column, 'like', '%ÃÂ¥%')
                            ->orWhere($column, 'like', '%Ã Â¤%');
                    })
                    ->orderBy($pk);

                $query->chunkById($chunk, function ($rows) use (
                    $table,
                    $column,
                    $pk,
                    $apply,
                    $sampleN,
                    &$scanned,
                    &$candidates,
                    &$repaired,
                    &$failed,
                    &$remaining,
                    &$columnCandidates,
                    &$columnRepaired,
                    &$samplesPrinted,
                ) {
                    foreach ($rows as $row) {
                        if ($remaining <= 0) {
                            return false;
                        }

                        $scanned++;
                        $original = (string) $row->{$column};
                        $fixed = Utf8MojibakeRepair::repair($original);
                        if ($fixed === null) {
                            continue;
                        }

                        $candidates++;
                        $columnCandidates++;
                        $remaining--;

                        if ($samplesPrinted < $sampleN) {
                            $this->line("  sample {$table}.{$column}#{$row->{$pk}}");
                            $this->line('    before: '.mb_substr($original, 0, 80, 'UTF-8'));
                            $this->line('    after:  '.mb_substr($fixed, 0, 80, 'UTF-8'));
                            $samplesPrinted++;
                        }

                        if (! $apply) {
                            $columnRepaired++;
                            $repaired++;

                            continue;
                        }

                        try {
                            $affected = DB::table($table)
                                ->where($pk, $row->{$pk})
                                ->where($column, $original)
                                ->update([$column => $fixed]);

                            if ($affected > 0) {
                                $columnRepaired++;
                                $repaired++;
                            } else {
                                $failed++;
                            }
                        } catch (Throwable $e) {
                            $failed++;
                            $this->error("update failed {$table}.{$column}#{$row->{$pk}}: ".$e->getMessage());
                        }
                    }

                    return $remaining > 0;
                }, $pk);
            } catch (Throwable $e) {
                $this->error("scan failed {$qualified}: ".$e->getMessage());
                $failed++;

                continue;
            }

            if ($columnCandidates > 0) {
                $this->info("{$qualified}: candidates={$columnCandidates} ".($apply ? "updated={$columnRepaired}" : "would_update={$columnRepaired}"));
            }
        }

        $this->newLine();
        $this->info("Done. scanned_rows={$scanned} candidates={$candidates} ".($apply ? "updated={$repaired}" : "would_update={$repaired}")." failed={$failed}");

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to persist (local env), or --apply --force outside local.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{table: string, column: string}>
     */
    private function discoverColumns(?string $tableFilter, ?string $columnFilter): array
    {
        $db = (string) config('database.connections.mysql.database');

        $sql = "SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND CHARACTER_SET_NAME = 'utf8mb4'
              AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','tinytext','char')";
        $bindings = [$db];

        if ($tableFilter) {
            $sql .= ' AND TABLE_NAME = ?';
            $bindings[] = $tableFilter;
        }
        if ($columnFilter) {
            $sql .= ' AND COLUMN_NAME = ?';
            $bindings[] = $columnFilter;
        }

        $sql .= ' ORDER BY TABLE_NAME, COLUMN_NAME';

        $rows = DB::select($sql, $bindings);

        return array_map(
            static fn ($row): array => [
                'table' => (string) $row->TABLE_NAME,
                'column' => (string) $row->COLUMN_NAME,
            ],
            $rows,
        );
    }

    private function primaryKey(string $table): ?string
    {
        $db = (string) config('database.connections.mysql.database');
        $keys = DB::select(
            "SELECT COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
            [$db, $table]
        );

        if (count($keys) !== 1) {
            return null;
        }

        return (string) $keys[0]->COLUMN_NAME;
    }
}
