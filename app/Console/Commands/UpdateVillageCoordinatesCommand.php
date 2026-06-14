<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class UpdateVillageCoordinatesCommand extends Command
{
    protected $signature = 'addresses:update-village-coordinates
        {path : CSV with lgd_code,lat,lng coordinate corrections}
        {--dry-run : Validate and summarize without writing}';

    protected $description = 'Update addresses village lat/lng from a validated LGD coordinate CSV';

    /** @var list<string> */
    private const REQUIRED_HEADERS = ['lgd_code', 'lat', 'lng'];

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->schemaReady()) {
            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error('Coordinate CSV file not found: '.$path);

            return self::FAILURE;
        }

        $dbLgds = $this->dbVillageLgdSet();
        if ($dbLgds === []) {
            $this->error('No village rows with lgd_code were found in addresses.');

            return self::FAILURE;
        }

        $nullVillageLgds = DB::table(Location::geoTable())
            ->where('hierarchy', 'village')
            ->whereNull('lgd_code')
            ->count();
        if ($nullVillageLgds > 0) {
            $this->error("Cannot update safely: {$nullVillageLgds} village rows have NULL lgd_code.");

            return self::FAILURE;
        }

        try {
            $plan = $this->validateCoordinateCsv($path, $dbLgds);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($plan, count($dbLgds), $dryRun);

        if ($plan['invalid_count'] > 0 || $plan['missing_count'] > 0) {
            $this->error('Coordinate update aborted: validation failed.');
            $this->printInvalidRows($plan['invalid_rows']);
            $this->printMissingLgds($plan['missing_lgds']);

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry-run complete. No rows were written.');

            return self::SUCCESS;
        }

        try {
            $result = $this->applyCoordinateCsv($path, $dbLgds);
        } catch (Throwable $e) {
            report($e);
            $this->error('Coordinate update failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Coordinate update complete.');
        $this->line('Village rows targeted: '.$result['targeted']);
        $this->line('Database rows affected: '.$result['affected']);

        return self::SUCCESS;
    }

    private function schemaReady(): bool
    {
        if (! Schema::hasTable(Location::geoTable())) {
            $this->error('addresses table is missing. Run migrations first.');

            return false;
        }

        foreach (['hierarchy', 'lgd_code', 'lat', 'lng', 'updated_at'] as $column) {
            if (! Schema::hasColumn(Location::geoTable(), $column)) {
                $this->error("addresses.{$column} column is missing.");

                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function dbVillageLgdSet(): array
    {
        $set = [];
        DB::table(Location::geoTable())
            ->where('hierarchy', 'village')
            ->whereNotNull('lgd_code')
            ->orderBy('lgd_code')
            ->pluck('lgd_code')
            ->each(function (mixed $lgdCode) use (&$set): void {
                $lgdCode = trim((string) $lgdCode);
                if ($lgdCode !== '') {
                    $set[$this->lgdKey($lgdCode)] = $lgdCode;
                }
            });

        return $set;
    }

    /**
     * @param  array<string, string>  $dbLgds
     * @return array<string, mixed>
     */
    private function validateCoordinateCsv(string $path, array $dbLgds): array
    {
        $missing = $dbLgds;
        $seen = [];
        $sources = [];
        $invalidRows = [];
        $invalidCount = 0;
        $rows = 0;
        $matching = 0;
        $extra = 0;

        foreach ($this->coordinateRows($path) as $row) {
            $rows++;
            $lgdCode = trim((string) $row['lgd_code']);
            $key = $this->lgdKey($lgdCode);

            if ($lgdCode === '') {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, (int) $row['_row'], 'lgd_code', $lgdCode, 'required field is blank');

                continue;
            }

            if (isset($seen[$key])) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, (int) $row['_row'], 'lgd_code', $lgdCode, 'duplicate lgd_code');

                continue;
            }
            $seen[$key] = true;

            foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $field => [$min, $max]) {
                $value = trim((string) $row[$field]);
                if ($value === '' || ! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                    [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, (int) $row['_row'], $field, $value, 'invalid coordinate');
                }
            }

            if (! $this->withinIndiaBounds((float) $row['lat'], (float) $row['lng'])) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, (int) $row['_row'], 'lat/lng', $row['lat'].','.$row['lng'], 'coordinate is outside India bounds');
            }

            $source = trim((string) ($row['source'] ?? ''));
            $source = $source !== '' ? $source : 'unknown';
            $sources[$source] = ($sources[$source] ?? 0) + 1;

            if (isset($dbLgds[$key])) {
                unset($missing[$key]);
                $matching++;
            } else {
                $extra++;
            }
        }

        return [
            'rows' => $rows,
            'matching' => $matching,
            'extra' => $extra,
            'sources' => $sources,
            'invalid_count' => $invalidCount,
            'invalid_rows' => $invalidRows,
            'missing_count' => count($missing),
            'missing_lgds' => array_slice(array_values($missing), 0, 100),
        ];
    }

    /**
     * @param  array<string, string>  $dbLgds
     * @return array{targeted: int, affected: int}
     */
    private function applyCoordinateCsv(string $path, array $dbLgds): array
    {
        $targeted = 0;
        $affected = 0;
        $chunk = [];

        DB::transaction(function () use ($path, $dbLgds, &$targeted, &$affected, &$chunk): void {
            foreach ($this->coordinateRows($path) as $row) {
                $lgdCode = trim((string) $row['lgd_code']);
                if (! isset($dbLgds[$this->lgdKey($lgdCode)])) {
                    continue;
                }

                $chunk[] = [
                    'lgd_code' => $lgdCode,
                    'lat' => $this->decimalString((string) $row['lat']),
                    'lng' => $this->decimalString((string) $row['lng']),
                ];
                $targeted++;

                if (count($chunk) >= 500) {
                    $affected += $this->updateChunk($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $affected += $this->updateChunk($chunk);
                $chunk = [];
            }
        });

        return [
            'targeted' => $targeted,
            'affected' => $affected,
        ];
    }

    /**
     * @param  list<array{lgd_code: string, lat: string, lng: string}>  $rows
     */
    private function updateChunk(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable(Location::geoTable());
        $lgd = $grammar->wrap('lgd_code');
        $lat = $grammar->wrap('lat');
        $lng = $grammar->wrap('lng');
        $hierarchy = $grammar->wrap('hierarchy');
        $updatedAt = $grammar->wrap('updated_at');

        $latCases = [];
        $lngCases = [];
        $bindings = [];

        foreach ($rows as $row) {
            $latCases[] = 'WHEN ? THEN ?';
            $bindings[] = $row['lgd_code'];
            $bindings[] = $row['lat'];
        }

        foreach ($rows as $row) {
            $lngCases[] = 'WHEN ? THEN ?';
            $bindings[] = $row['lgd_code'];
            $bindings[] = $row['lng'];
        }

        $bindings[] = now()->toDateTimeString();

        $placeholders = implode(',', array_fill(0, count($rows), '?'));
        foreach ($rows as $row) {
            $bindings[] = $row['lgd_code'];
        }

        $sql = "UPDATE {$table}
            SET {$lat} = CASE {$lgd} ".implode(' ', $latCases)." ELSE {$lat} END,
                {$lng} = CASE {$lgd} ".implode(' ', $lngCases)." ELSE {$lng} END,
                {$updatedAt} = ?
            WHERE {$hierarchy} = 'village'
              AND {$lgd} IN ({$placeholders})";

        return DB::update($sql, $bindings);
    }

    /**
     * @return \Generator<int, array<string, string|int>>
     */
    private function coordinateRows(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open coordinate CSV: '.$path);
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('Coordinate CSV is empty: '.$path);
            }

            $header = array_map(static function (mixed $value): string {
                $value = trim((string) $value);

                return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            }, $header);

            foreach (self::REQUIRED_HEADERS as $required) {
                if (! in_array($required, $header, true)) {
                    throw new RuntimeException("Coordinate CSV is missing required header [{$required}].");
                }
            }

            $rowNumber = 1;
            while (($raw = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($this->isBlankCsvRow($raw)) {
                    continue;
                }

                $row = ['_row' => $rowNumber];
                foreach ($header as $index => $name) {
                    $row[$name] = isset($raw[$index]) ? trim((string) $raw[$index]) : '';
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function withinIndiaBounds(float $lat, float $lng): bool
    {
        return $lat >= 5.5 && $lat <= 38.8 && $lng >= 67.0 && $lng <= 98.8;
    }

    private function decimalString(string $value): string
    {
        return number_format((float) $value, 7, '.', '');
    }

    private function lgdKey(string $lgdCode): string
    {
        return 'lgd:'.trim($lgdCode);
    }

    /**
     * @param  list<array<string, mixed>>  $invalidRows
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function addInvalid(array $invalidRows, int $invalidCount, int $row, string $field, string $value, string $reason): array
    {
        $invalidCount++;
        if (count($invalidRows) < 100) {
            $invalidRows[] = [
                'row' => $row,
                'field' => $field,
                'value' => $value,
                'reason' => $reason,
            ];
        }

        return [$invalidRows, $invalidCount];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printSummary(array $plan, int $dbVillageCount, bool $dryRun): void
    {
        $this->line('Coordinate validation summary:');
        $this->line('Village rows in DB: '.$dbVillageCount);
        $this->line('Coordinate rows read: '.$plan['rows']);
        $this->line('Coordinate rows matching DB villages: '.$plan['matching']);
        $this->line('Extra coordinate rows ignored: '.$plan['extra']);
        $this->line('Missing DB village coordinates: '.$plan['missing_count']);
        $this->line('Invalid rows: '.$plan['invalid_count']);
        $this->line(($dryRun ? 'Rows that would be updated: ' : 'Rows to update: ').$plan['matching']);

        foreach ($plan['sources'] as $source => $count) {
            $this->line("Source {$source}: {$count}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $invalidRows
     */
    private function printInvalidRows(array $invalidRows): void
    {
        foreach ($invalidRows as $row) {
            $this->line(sprintf(
                'Invalid row %d: %s [%s] %s',
                (int) $row['row'],
                (string) $row['field'],
                (string) $row['value'],
                (string) $row['reason'],
            ));
        }
    }

    /**
     * @param  list<string>  $missingLgds
     */
    private function printMissingLgds(array $missingLgds): void
    {
        if ($missingLgds === []) {
            return;
        }

        $this->line('Missing lgd_code values (first '.count($missingLgds).'): '.implode(', ', $missingLgds));
    }
}
