<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds states, districts, talukas, villages from JSON files in database/seeders/data/geo/.
 * Uses firstOrCreate so safe to run after MinimalLocationSeeder / LocationSeeder.
 * Links by codes: districtcode -> district id, subdistrictcode -> taluka id.
 */
class GeoSeeder extends Seeder
{
    private string $geoPath;

    public function __construct()
    {
        $this->geoPath = base_path('database/seeders/data/geo');
    }

    public function run(): void
    {
        if (! is_dir($this->geoPath)) {
            $this->command->warn('Geo data directory not found: ' . $this->geoPath);

            return;
        }

        $india = Country::firstOrCreate(['name' => 'India']);

        $stateCodeToId = $this->seedStates($india->id);
        if ($stateCodeToId === []) {
            $this->command->warn('No states loaded. Ensure states.json exists and has statecode, statenameenglish.');

            return;
        }

        $districtCodeToId = $this->seedDistricts($stateCodeToId);
        $subdistrictCodeToId = $this->seedTalukas($districtCodeToId);
        $this->seedVillages($subdistrictCodeToId);
    }

    /** @return array<string, int> statecode -> id */
    private function seedStates(int $countryId): array
    {
        $path = $this->geoPath . '/states.json';
        if (! is_readable($path)) {
            return [];
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return [];
        }

        $seen = [];
        $map = [];

        foreach ($rows as $row) {
            $code = isset($row['statecode']) ? (string) $row['statecode'] : null;
            $nameEn = $row['statenameenglish'] ?? $row['name'] ?? null;
            $nameMr = $row['statelocalname'] ?? null;
            if ($code === null || $nameEn === null) {
                continue;
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $state = State::firstOrCreate(
                ['country_id' => $countryId, 'name' => $nameEn]
            );
            if ($nameMr && ! $state->name_mr) {
                $state->name_mr = trim($nameMr);
                $state->save();
            }
            $map[$code] = $state->id;
        }

        $this->command->info('States: ' . count($map) . ' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $stateCodeToId
     * @return array<string, int> districtcode -> id
     */
    private function seedDistricts(array $stateCodeToId): array
    {
        $path = $this->geoPath . '/districts.json';
        if (! is_readable($path)) {
            return [];
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return [];
        }

        $map = [];
        $stateId = reset($stateCodeToId);

        foreach ($rows as $row) {
            $code = isset($row['districtcode']) ? (string) $row['districtcode'] : null;
            $nameEn = $row['districtnameenglish'] ?? $row['name'] ?? null;
            $nameMr = $row['districtlocalname'] ?? null;
            if ($code === null || $nameEn === null) {
                continue;
            }
            $district = District::firstOrCreate(
                ['state_id' => $stateId, 'name' => $nameEn]
            );
            if ($nameMr && ! $district->name_mr) {
                $district->name_mr = trim($nameMr);
                $district->save();
            }
            $map[$code] = $district->id;
        }

        $this->command->info('Districts: ' . count($map) . ' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $districtCodeToId
     * @return array<string, int> subdistrictcode -> id
     */
    private function seedTalukas(array $districtCodeToId): array
    {
        $path = $this->geoPath . '/talukas.json';
        if (! is_readable($path)) {
            return [];
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $dCode = isset($row['districtcode']) ? (string) $row['districtcode'] : null;
            $sdCode = isset($row['subdistrictcode']) ? (string) $row['subdistrictcode'] : null;
            $nameEn = $row['subdistrictnameenglish'] ?? $row['name'] ?? null;
            $nameMr = $row['subdistrictlocalname'] ?? null;
            if ($dCode === null || $sdCode === null || $nameEn === null) {
                continue;
            }
            $districtId = $districtCodeToId[$dCode] ?? null;
            if ($districtId === null) {
                continue;
            }
            $taluka = Taluka::firstOrCreate(
                ['district_id' => $districtId, 'name' => $nameEn]
            );
            if ($nameMr && ! $taluka->name_mr) {
                $taluka->name_mr = trim($nameMr);
                $taluka->save();
            }
            $map[$sdCode] = $taluka->id;
        }

        $this->command->info('Talukas: ' . count($map) . ' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $subdistrictCodeToId
     */
    private function seedVillages(array $subdistrictCodeToId): void
    {
        $path = $this->geoPath . '/villages.json';
        if (! is_readable($path)) {
            $this->command->warn('Villages JSON not found or not readable.');

            return;
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return;
        }

        $count = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $sdCode = isset($row['subdistrictcode']) ? (string) $row['subdistrictcode'] : null;
            $lgdCode = isset($row['villagecode']) ? trim((string) $row['villagecode']) : null;
            $nameEn = $row['villagenameenglish'] ?? $row['name'] ?? null;
            $nameMr = $row['villagelocalname'] ?? null;

            if ($sdCode === null || $lgdCode === null || $lgdCode === '' || $nameEn === null || $nameEn === '') {
                $skipped++;
                continue;
            }
            $talukaId = $subdistrictCodeToId[$sdCode] ?? null;
            if ($talukaId === null) {
                $skipped++;
                continue;
            }
            $village = Village::updateOrCreate(
                ['lgd_code' => $lgdCode],
                [
                    'taluka_id' => $talukaId,
                    'name_en' => trim($nameEn),
                    'name_mr' => $nameMr ? trim($nameMr) : null,
                    'name' => trim($nameEn),
                    'is_active' => true,
                ]
            );

            // Mirror into cities table so existing location search (city-based) sees this place.
            City::firstOrCreate(
                [
                    'taluka_id' => $talukaId,
                    'name' => trim($nameEn),
                ]
            );

            $count++;
        }

        $this->command->info('Villages: ' . $count . ' loaded, ' . $skipped . ' skipped.');
    }
}
