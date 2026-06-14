<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\Location\AddressSchemaEnumOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ImportCleanAddressesCsvCommand extends Command
{
    protected $signature = 'addresses:import-clean-csv
        {path : Full path to the clean address CSV}
        {--fresh : Delete existing addresses before inserting}
        {--dry-run : Validate and summarize without writing}';

    protected $description = 'Import clean India hierarchy CSV into addresses with strict hierarchy/tag separation';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'S.No.',
        'State Code',
        'State Name (In English)',
        'District Code',
        'District Name (In English)',
        'Sub-District Code',
        'Sub-District Name (In English)',
        'lgd_code',
        'Village Name (In English)',
        'Village Name (In Local)',
        'name_mr',
        'Pincode',
        'Latitude',
        'Longitude',
        'Tag',
    ];

    /** @var list<string> */
    private const ALLOWED_HIERARCHIES = ['country', 'state', 'district', 'taluka', 'village'];

    /** @var list<string> */
    private const ALLOWED_TAGS = ['city', 'suburban', 'rural'];

    private const COUNTRY_ID = 1;

    private const COUNTRY_NAME = 'India';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $fresh = (bool) $this->option('fresh');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->schemaReady()) {
            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error('CSV file not found: '.$path);

            return self::FAILURE;
        }

        $plan = $this->validateCsv($path);
        $this->printValidationSummary($plan);

        if ($plan['invalid_count'] > 0) {
            $this->error('Import aborted: CSV validation failed.');
            $this->printInvalidRows($plan['invalid_rows']);

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry-run complete. No rows were written.');

            return self::SUCCESS;
        }

        if (! $fresh && DB::table(Location::geoTable())->exists()) {
            $this->error('addresses already contains rows. Re-run with --fresh for this one-time clean import.');

            return self::FAILURE;
        }

        if ($fresh && ! $this->assertNoExternalAddressReferences()) {
            return self::FAILURE;
        }

        try {
            $summary = $this->importCsv($path, $plan, $fresh);
        } catch (Throwable $e) {
            report($e);
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import complete.');
        $this->line('Countries inserted: '.$summary['countries']);
        $this->line('States inserted: '.$summary['states']);
        $this->line('Districts inserted: '.$summary['districts']);
        $this->line('Talukas inserted: '.$summary['talukas']);
        $this->line('Villages inserted: '.$summary['villages']);
        $this->line('Skipped rows: '.$summary['skipped']);
        $this->line('Invalid rows: 0');

        return self::SUCCESS;
    }

    private function schemaReady(): bool
    {
        if (! Schema::hasTable(Location::geoTable())) {
            $this->error('addresses table is missing. Run migrations first.');

            return false;
        }

        if (Schema::hasColumn(Location::geoTable(), 'type')) {
            $this->error('Legacy address type column still exists. Run migrations first.');

            return false;
        }

        if (Schema::hasColumn(Location::geoTable(), 'iso_alpha2')) {
            $this->error('Legacy address iso_alpha2 column still exists. Run migrations first.');

            return false;
        }

        foreach (['id', 'name', 'slug', 'hierarchy', 'tag', 'parent_id', 'level', 'is_active', 'created_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn(Location::geoTable(), $column)) {
                $this->error("addresses.{$column} column is missing.");

                return false;
            }
        }

        $hierarchyExtra = array_values(array_diff(AddressSchemaEnumOptions::addressHierarchies(), self::ALLOWED_HIERARCHIES));
        if ($hierarchyExtra !== []) {
            $this->error('addresses.hierarchy still allows invalid values: '.implode(', ', $hierarchyExtra).'. Run migrations first.');

            return false;
        }

        $tagExtra = array_values(array_diff(AddressSchemaEnumOptions::addressTags(), self::ALLOWED_TAGS));
        if ($tagExtra !== []) {
            $this->error('addresses.tag still allows invalid values: '.implode(', ', $tagExtra).'. Run migrations first.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCsv(string $path): array
    {
        $handle = $this->openCsv($path);
        $header = $this->readHeader($handle);
        $headerMap = $this->headerMap($header);

        $invalidRows = [];
        $invalidCount = 0;
        $skipped = 0;

        $states = [];
        $districts = [];
        $talukas = [];
        $villageKeys = [];
        $lgdKeys = [];
        $tagCounts = array_fill_keys(self::ALLOWED_TAGS, 0);
        $blankRequired = array_fill_keys([
            'state_code',
            'state_name',
            'district_code',
            'district_name',
            'sub_district_code',
            'sub_district_name',
            'lgd_code',
            'village_name',
        ], 0);

        $rowNumber = 1;
        while (($raw = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($this->isBlankCsvRow($raw)) {
                $skipped++;

                continue;
            }

            $row = $this->rowFromCsv($header, $raw);

            $stateCode = $this->cell($row, 'State Code');
            $stateName = $this->cell($row, 'State Name (In English)');
            $districtCode = $this->cell($row, 'District Code');
            $districtName = $this->cell($row, 'District Name (In English)');
            $subDistrictCode = $this->cell($row, 'Sub-District Code');
            $subDistrictName = $this->cell($row, 'Sub-District Name (In English)');
            $lgdCode = $this->cell($row, 'lgd_code');
            $villageName = $this->cell($row, 'Village Name (In English)');

            $required = [
                'state_code' => $stateCode,
                'state_name' => $stateName,
                'district_code' => $districtCode,
                'district_name' => $districtName,
                'sub_district_code' => $subDistrictCode,
                'sub_district_name' => $subDistrictName,
                'lgd_code' => $lgdCode,
                'village_name' => $villageName,
            ];
            foreach ($required as $field => $value) {
                if ($value === '') {
                    $blankRequired[$field]++;
                    [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, $field, $value, 'required field is blank');
                }
            }
            if (in_array('', $required, true)) {
                continue;
            }

            $tag = $this->cell($row, 'Tag');
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, 'Tag', $this->cell($row, 'Tag'), 'invalid tag');

                continue;
            }
            $tagCounts[$tag]++;

            foreach (['Latitude' => [-90, 90], 'Longitude' => [-180, 180]] as $field => [$min, $max]) {
                $value = $this->cell($row, $field);
                if ($value !== '' && (! is_numeric($value) || (float) $value < $min || (float) $value > $max)) {
                    [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, $field, $value, 'invalid coordinate');
                }
            }

            $pincode = $this->cell($row, 'Pincode');
            if ($pincode !== '' && ! preg_match('/^\d{3,10}$/', $pincode)) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, 'Pincode', $pincode, 'pincode must be 3-10 digits');
            }

            $stateKey = $this->key($stateCode);
            $districtKey = $this->key($stateCode, $districtCode);
            $talukaKey = $this->key($stateCode, $districtCode, $subDistrictCode);
            $villageKey = $this->key($stateCode, $districtCode, $subDistrictCode, $villageName);

            $states = $this->putUniqueHierarchy($states, $stateKey, [
                'code' => $stateCode,
                'name' => $stateName,
            ], $rowNumber, 'state', $invalidRows, $invalidCount);

            $districts = $this->putUniqueHierarchy($districts, $districtKey, [
                'state_key' => $stateKey,
                'code' => $districtCode,
                'name' => $districtName,
            ], $rowNumber, 'district', $invalidRows, $invalidCount);

            $talukas = $this->putUniqueHierarchy($talukas, $talukaKey, [
                'district_key' => $districtKey,
                'code' => $subDistrictCode,
                'name' => $subDistrictName,
            ], $rowNumber, 'taluka', $invalidRows, $invalidCount);

            if (isset($villageKeys[$villageKey])) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, 'Village Name (In English)', $villageName, 'duplicate hierarchy village key');
            }
            $villageKeys[$villageKey] = true;

            if (isset($lgdKeys[$lgdCode])) {
                [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, $rowNumber, 'lgd_code', $lgdCode, 'duplicate lgd_code');
            }
            $lgdKeys[$lgdCode] = true;
        }

        fclose($handle);

        if ($headerMap === []) {
            [$invalidRows, $invalidCount] = $this->addInvalid($invalidRows, $invalidCount, 1, 'headers', implode(',', $header), 'missing required CSV headers');
        }

        return [
            'invalid_count' => $invalidCount,
            'invalid_rows' => $invalidRows,
            'skipped' => $skipped,
            'states' => $states,
            'districts' => $districts,
            'talukas' => $talukas,
            'village_count' => count($villageKeys),
            'tag_counts' => $tagCounts,
            'blank_required' => $blankRequired,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, mixed>  $value
     * @param  list<array<string, mixed>>  $invalidRows
     * @return array<string, array<string, mixed>>
     */
    private function putUniqueHierarchy(array $items, string $key, array $value, int $rowNumber, string $label, array &$invalidRows, int &$invalidCount): array
    {
        if (! isset($items[$key])) {
            $items[$key] = $value;

            return $items;
        }

        if (($items[$key]['name'] ?? '') !== ($value['name'] ?? '')) {
            [$invalidRows, $invalidCount] = $this->addInvalid(
                $invalidRows,
                $invalidCount,
                $rowNumber,
                $label,
                (string) ($value['name'] ?? ''),
                "conflicting {$label} name for the same code"
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, int>
     */
    private function importCsv(string $path, array $plan, bool $fresh): array
    {
        $states = $this->assignIds($plan['states'], self::COUNTRY_ID + 1);
        $nextId = self::COUNTRY_ID + 1 + count($states);
        $districts = $this->assignIds($plan['districts'], $nextId);
        $nextId += count($districts);
        $talukas = $this->assignIds($plan['talukas'], $nextId);
        $nextId += count($talukas);

        $columns = $this->addressColumns();
        $now = now()->toDateTimeString();
        $slugUsage = [];
        $summary = [
            'countries' => 1,
            'states' => count($states),
            'districts' => count($districts),
            'talukas' => count($talukas),
            'villages' => 0,
            'skipped' => (int) $plan['skipped'],
        ];

        DB::transaction(function () use ($path, $fresh, $states, $districts, $talukas, $columns, $now, &$summary, &$nextId, &$slugUsage): void {
            if ($fresh) {
                if (DB::table(Location::geoTable())->count() === 0) {
                    $this->clearStaleExternalAddressReferences();
                } else {
                    DB::table(Location::geoTable())->delete();
                }
            }

            $this->insertAddressRows([
                $this->addressRow([
                    'id' => self::COUNTRY_ID,
                    'parent_id' => null,
                    'name' => self::COUNTRY_NAME,
                    'name_en' => self::COUNTRY_NAME,
                    'name_mr' => null,
                    'slug' => $this->uniqueSlug(self::COUNTRY_NAME, null, 'country', $slugUsage),
                    'hierarchy' => 'country',
                    'tag' => null,
                    'level' => 0,
                    'lgd_code' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $columns),
            ]);

            $rows = [];
            foreach ($states as $state) {
                $rows[] = $this->addressRow([
                    'id' => $state['id'],
                    'parent_id' => self::COUNTRY_ID,
                    'name' => $state['name'],
                    'name_en' => $state['name'],
                    'name_mr' => null,
                    'slug' => $this->uniqueSlug((string) $state['name'], self::COUNTRY_ID, 'state', $slugUsage),
                    'hierarchy' => 'state',
                    'tag' => null,
                    'level' => 1,
                    'lgd_code' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $columns);
            }
            $this->insertAddressRows($rows);

            $rows = [];
            foreach ($districts as $district) {
                $rows[] = $this->addressRow([
                    'id' => $district['id'],
                    'parent_id' => $states[$district['state_key']]['id'],
                    'name' => $district['name'],
                    'name_en' => $district['name'],
                    'name_mr' => null,
                    'slug' => $this->uniqueSlug((string) $district['name'], (int) $states[$district['state_key']]['id'], 'district', $slugUsage),
                    'hierarchy' => 'district',
                    'tag' => 'city',
                    'level' => 2,
                    'lgd_code' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $columns);
            }
            $this->insertAddressRows($rows);

            $rows = [];
            foreach ($talukas as $taluka) {
                $rows[] = $this->addressRow([
                    'id' => $taluka['id'],
                    'parent_id' => $districts[$taluka['district_key']]['id'],
                    'name' => $taluka['name'],
                    'name_en' => $taluka['name'],
                    'name_mr' => null,
                    'slug' => $this->uniqueSlug((string) $taluka['name'], (int) $districts[$taluka['district_key']]['id'], 'taluka', $slugUsage),
                    'hierarchy' => 'taluka',
                    'tag' => 'suburban',
                    'level' => 3,
                    'lgd_code' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $columns);
            }
            $this->insertAddressRows($rows);

            $this->insertVillages($path, $talukas, $columns, $now, $nextId, $summary, $slugUsage);
        });

        $this->resetAutoIncrement($nextId);

        return $summary;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function assignIds(array $items, int $start): array
    {
        ksort($items, SORT_NATURAL);
        $id = $start;
        foreach ($items as $key => $item) {
            $items[$key]['id'] = $id++;
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, mixed>>  $talukas
     * @param  array<string, true>  $columns
     * @param  array<string, int>  $summary
     */
    private function insertVillages(string $path, array $talukas, array $columns, string $now, int &$nextId, array &$summary, array &$slugUsage): void
    {
        $handle = $this->openCsv($path);
        $header = $this->readHeader($handle);
        $chunk = [];

        while (($raw = fgetcsv($handle)) !== false) {
            if ($this->isBlankCsvRow($raw)) {
                continue;
            }

            $row = $this->rowFromCsv($header, $raw);
            $stateCode = $this->cell($row, 'State Code');
            $districtCode = $this->cell($row, 'District Code');
            $subDistrictCode = $this->cell($row, 'Sub-District Code');
            $talukaKey = $this->key($stateCode, $districtCode, $subDistrictCode);
            $lgdCode = $this->cell($row, 'lgd_code');
            $villageName = $this->cell($row, 'Village Name (In English)');
            $localName = $this->cell($row, 'name_mr') ?: $this->cell($row, 'Village Name (In Local)');

            $chunk[] = $this->addressRow([
                'id' => $nextId++,
                'parent_id' => $talukas[$talukaKey]['id'],
                'name' => $villageName,
                'name_en' => $villageName,
                'name_mr' => $localName !== '' ? $localName : null,
                'slug' => $this->uniqueSlug($villageName, (int) $talukas[$talukaKey]['id'], 'village', $slugUsage),
                'hierarchy' => 'village',
                'tag' => $this->cell($row, 'Tag'),
                'level' => 4,
                'pincode' => $this->cell($row, 'Pincode') ?: null,
                'lat' => $this->decimalOrNull($this->cell($row, 'Latitude')),
                'lng' => $this->decimalOrNull($this->cell($row, 'Longitude')),
                'lgd_code' => $lgdCode,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $columns);

            $summary['villages']++;

            if (count($chunk) >= 1000) {
                $this->insertAddressRows($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->insertAddressRows($chunk);
        }

        fclose($handle);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertAddressRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(Location::geoTable())->insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $columns
     * @return array<string, mixed>
     */
    private function addressRow(array $values, array $columns): array
    {
        $row = [];
        foreach ($columns as $column => $_) {
            if (array_key_exists($column, $values)) {
                $row[$column] = $values[$column];
            }
        }

        return $row;
    }

    /**
     * @return array<string, true>
     */
    private function addressColumns(): array
    {
        return array_fill_keys(Schema::getColumnListing(Location::geoTable()), true);
    }

    private function assertNoExternalAddressReferences(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $database = DB::getDatabaseName();
        $refs = DB::select(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.TABLE_SCHEMA = ?
               AND k.REFERENCED_TABLE_NAME = ?
               AND NOT (k.TABLE_NAME = ? AND k.COLUMN_NAME = ?)
             ORDER BY k.TABLE_NAME, k.COLUMN_NAME',
            [$database, Location::geoTable(), Location::geoTable(), 'parent_id']
        );

        $blocking = [];
        foreach ($refs as $ref) {
            $table = (string) $ref->TABLE_NAME;
            $column = (string) $ref->COLUMN_NAME;
            $count = (int) DB::selectOne(
                'SELECT COUNT(*) AS total FROM `'.str_replace('`', '``', $table).'` WHERE `'.str_replace('`', '``', $column).'` IS NOT NULL'
            )->total;
            if ($count > 0) {
                $blocking[] = "{$table}.{$column}={$count}";
            }
        }

        if ($blocking === []) {
            return true;
        }

        if (DB::table(Location::geoTable())->count() === 0) {
            $this->warn('addresses is empty, but stale external address references exist. They will be nulled/deleted before import to avoid accidental rebinding: '.implode(', ', $blocking));

            return true;
        }

        $this->error('Refusing --fresh because other tables still reference addresses: '.implode(', ', $blocking));

        return false;
    }

    private function clearStaleExternalAddressReferences(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();
        $refs = DB::select(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, c.IS_NULLABLE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
              AND c.TABLE_NAME = k.TABLE_NAME
              AND c.COLUMN_NAME = k.COLUMN_NAME
             WHERE k.TABLE_SCHEMA = ?
               AND k.REFERENCED_TABLE_NAME = ?
               AND NOT (k.TABLE_NAME = ? AND k.COLUMN_NAME = ?)
             ORDER BY k.TABLE_NAME, k.COLUMN_NAME',
            [$database, Location::geoTable(), Location::geoTable(), 'parent_id']
        );

        foreach ($refs as $ref) {
            $table = (string) $ref->TABLE_NAME;
            $column = (string) $ref->COLUMN_NAME;
            $tableSql = '`'.str_replace('`', '``', $table).'`';
            $columnSql = '`'.str_replace('`', '``', $column).'`';
            $count = (int) DB::selectOne("SELECT COUNT(*) AS total FROM {$tableSql} WHERE {$columnSql} IS NOT NULL")->total;
            if ($count === 0) {
                continue;
            }

            if ((string) $ref->IS_NULLABLE === 'YES') {
                DB::statement("UPDATE {$tableSql} SET {$columnSql} = NULL WHERE {$columnSql} IS NOT NULL");
                $this->warn("Nulled stale {$table}.{$column}: {$count}");
            } else {
                DB::statement("DELETE FROM {$tableSql} WHERE {$columnSql} IS NOT NULL");
                $this->warn("Deleted stale {$table} rows via {$column}: {$count}");
            }
        }
    }

    private function resetAutoIncrement(int $nextId): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE '.Location::geoTable().' AUTO_INCREMENT = '.max(1, $nextId));
    }

    /**
     * @param  resource  $handle
     * @return list<string>
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle);
        if (! is_array($header)) {
            return [];
        }

        $header = array_map(static function (mixed $value): string {
            $value = trim((string) $value);

            return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        }, $header);

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $header, true)) {
                return [];
            }
        }

        return $header;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        if ($header === []) {
            return [];
        }

        return array_flip($header);
    }

    /**
     * @return resource
     */
    private function openCsv(string $path)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV: '.$path);
        }

        return $handle;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $raw
     * @return array<string, string>
     */
    private function rowFromCsv(array $header, array $raw): array
    {
        $row = [];
        foreach ($header as $index => $name) {
            $row[$name] = isset($raw[$index]) ? trim((string) $raw[$index]) : '';
        }

        return $row;
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

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function key(string ...$parts): string
    {
        return mb_strtolower(implode('|', array_map(static fn (string $part): string => trim($part), $parts)), 'UTF-8');
    }

    /**
     * @param  array<string, int>  $slugUsage
     */
    private function uniqueSlug(string $name, ?int $parentId, string $hierarchy, array &$slugUsage): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'address';
        }

        $scope = ($parentId === null ? 'null' : (string) $parentId).'|'.$hierarchy;
        $key = $scope.'|'.$base;
        $slugUsage[$key] = ($slugUsage[$key] ?? 0) + 1;

        return $slugUsage[$key] === 1 ? $base : $base.'-'.$slugUsage[$key];
    }

    private function decimalOrNull(string $value): ?string
    {
        return $value !== '' ? $value : null;
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
    private function printValidationSummary(array $plan): void
    {
        $this->line('Validation summary:');
        $this->line('Countries: 1');
        $this->line('Unique states: '.count($plan['states']));
        $this->line('Unique districts: '.count($plan['districts']));
        $this->line('Unique talukas: '.count($plan['talukas']));
        $this->line('Villages: '.$plan['village_count']);
        $this->line('Skipped rows: '.$plan['skipped']);
        $this->line('Invalid rows: '.$plan['invalid_count']);

        foreach ($plan['tag_counts'] as $tag => $count) {
            $this->line("Tag {$tag}: {$count}");
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
}
