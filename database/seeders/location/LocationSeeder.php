<?php

namespace Database\Seeders\Location;

use App\Models\Location;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LocationSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private array $allowedTypes = [
        'country',
        'state',
        'district',
        'taluka',
        'city',
        'suburb',
        'village',
    ];

    /**
     * @var array<string, int>
     */
    private array $levelMap = [
        'country' => 0,
        'state' => 1,
        'district' => 2,
        'taluka' => 3,
        'city' => 4,
        'suburb' => 4,
        'village' => 4,
    ];

    public function run(): void
    {
        $this->call(LocationCountrySeeder::class);

        $this->seedFile('states.json', 'state');
        $this->seedFile('districts_maharashtra.json', 'district');
        $this->seedFile('talukas_maharashtra.json', 'taluka');
        $this->seedFile('cities_maharashtra.json', 'city');
        $this->seedFile('suburbs_maharashtra.json', 'suburb');
        $this->seedFile('villages_maharashtra.json', 'village');

        if (Schema::hasColumn((new Location)->getTable(), 'name_mr')) {
            LocationMarathiLabels::syncLocationsTableNameMr();
        }
    }

    private function seedFile(string $filename, string $expectedType): void
    {
        $rows = $this->readRows($filename);
        $this->assertNoDuplicateEntries($rows, $filename);

        foreach ($rows as $row) {
            $slug = $this->requiredString($row, 'slug', $filename);
            $name = $this->requiredString($row, 'name', $filename);
            $type = $this->requiredString($row, 'type', $filename);
            $parentSlug = $this->requiredString($row, 'parent_slug', $filename);
            $level = isset($row['level']) ? (int) $row['level'] : null;

            if ($type !== $expectedType) {
                throw new RuntimeException("Type mismatch in {$filename} for slug '{$slug}': expected '{$expectedType}', got '{$type}'.");
            }
            if (! in_array($type, $this->allowedTypes, true)) {
                throw new RuntimeException("Invalid type '{$type}' in {$filename} for slug '{$slug}'.");
            }

            $expectedLevel = $this->levelMap[$type] ?? null;
            if ($expectedLevel === null || $level !== $expectedLevel) {
                throw new RuntimeException("Invalid level in {$filename} for slug '{$slug}'. Expected {$expectedLevel}, got ".var_export($level, true).'.');
            }

            $parent = Location::query()->where('slug', $parentSlug)->first();
            if ($parent === null) {
                throw new RuntimeException("Missing parent slug '{$parentSlug}' for '{$slug}' in {$filename}.");
            }

            Location::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => (int) $parent->id,
                    'level' => $level,
                    'state_code' => $row['state_code'] ?? null,
                    'district_code' => $row['district_code'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $filename): array
    {
        $path = database_path('seeders/location/'.$filename);
        if (! is_file($path)) {
            throw new RuntimeException("Location dataset file missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return array_values(array_filter($decoded, static fn ($row) => is_array($row)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertNoDuplicateEntries(array $rows, string $filename): void
    {
        $slugSeen = [];
        $nameWithinParentSeen = [];

        foreach ($rows as $row) {
            $slug = isset($row['slug']) ? trim((string) $row['slug']) : '';
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $parent = isset($row['parent_slug']) ? trim((string) $row['parent_slug']) : '';

            if ($slug !== '') {
                if (isset($slugSeen[$slug])) {
                    throw new RuntimeException("Duplicate slug '{$slug}' in {$filename}.");
                }
                $slugSeen[$slug] = true;
            }

            if ($name !== '' && $parent !== '') {
                $k = mb_strtolower($parent.'|'.$name, 'UTF-8');
                if (isset($nameWithinParentSeen[$k])) {
                    throw new RuntimeException("Duplicate location name '{$name}' under parent '{$parent}' in {$filename}.");
                }
                $nameWithinParentSeen[$k] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requiredString(array $row, string $key, string $filename): string
    {
        $value = isset($row[$key]) ? trim((string) $row[$key]) : '';
        if ($value === '') {
            throw new RuntimeException("Missing required '{$key}' in {$filename}.");
        }

        return $value;
    }
}

