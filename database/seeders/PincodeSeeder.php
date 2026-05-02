<?php

namespace Database\Seeders;

use App\Models\Pincode;
use App\Models\Location;
use Illuminate\Database\Seeder;
use RuntimeException;

class PincodeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->readRows('pincodes_maharashtra.json');
        $seen = [];

        foreach ($rows as $row) {
            $pincode = $this->requiredString($row, 'pincode');
            $placeSlug = $this->requiredString($row, 'place_slug');
            $isPrimary = (bool) ($row['is_primary'] ?? false);
            $latitude = array_key_exists('latitude', $row) ? $this->nullableFloat($row['latitude']) : null;
            $longitude = array_key_exists('longitude', $row) ? $this->nullableFloat($row['longitude']) : null;

            $location = Location::query()->where('slug', $placeSlug)->first();
            if ($location === null) {
                throw new RuntimeException("Pincode '{$pincode}' references missing place slug '{$placeSlug}'.");
            }

            $k = mb_strtolower($pincode.'|'.$placeSlug, 'UTF-8');
            if (isset($seen[$k])) {
                throw new RuntimeException("Duplicate pincode mapping '{$pincode}' for place '{$placeSlug}' in dataset.");
            }
            $seen[$k] = true;

            Pincode::query()->updateOrCreate(
                [
                    'pincode' => $pincode,
                    'place_id' => (int) $location->id,
                ],
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_primary' => $isPrimary,
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
            throw new RuntimeException("Location pincode dataset file missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return array_values(array_filter($decoded, static fn ($row) => is_array($row)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requiredString(array $row, string $key): string
    {
        $value = isset($row[$key]) ? trim((string) $row[$key]) : '';
        if ($value === '') {
            throw new RuntimeException("Missing required '{$key}' in pincode dataset.");
        }

        return $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}

