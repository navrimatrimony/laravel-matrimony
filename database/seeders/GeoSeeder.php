<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use App\Services\Location\LocationNormalizationService;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

/**
 * Seeds states, districts, talukas, villages from JSON files in database/seeders/data/geo/.
 * Requires {@see CountriesMasterSeeder} first. Safe to run after MinimalLocationSeeder / LocationSeeder.
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
            $this->command->warn('Geo data directory not found: '.$this->geoPath);

            return;
        }

        $india = Country::query()->where('iso_alpha2', 'IN')->first();
        if ($india === null) {
            $this->command?->error('India (iso_alpha2=IN) missing. Run CountriesMasterSeeder first.');

            return;
        }

        $stateCodeToId = $this->seedStates($india->id);
        if ($stateCodeToId === []) {
            $this->command->warn('No states loaded. Ensure states.json exists and has statecode, statenameenglish.');

            return;
        }

        $districtCodeToId = $this->seedDistricts($stateCodeToId);
        $subdistrictCodeToId = $this->seedTalukas($districtCodeToId);
        $this->seedVillages($subdistrictCodeToId);
        $this->logGeoManifestIfPresent();
        $this->seedPromotedCitiesFromJson($stateCodeToId);

        LocationMarathiLabels::syncIndianStateNameMr();
        LocationMarathiLabels::syncIndianDistrictNameMr();
        LocationMarathiLabels::syncIndianTalukaNameMr();
    }

    /** @return array<string, int> statecode -> id */
    private function seedStates(int $countryId): array
    {
        $path = $this->geoPath.'/states.json';
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

        $this->command->info('States: '.count($map).' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $stateCodeToId
     * @return array<string, int> districtcode -> id
     */
    private function seedDistricts(array $stateCodeToId): array
    {
        $path = $this->geoPath.'/districts.json';
        if (! is_readable($path)) {
            return [];
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return [];
        }

        $map = [];
        $singleStateId = count($stateCodeToId) === 1 ? (int) reset($stateCodeToId) : null;

        foreach ($rows as $row) {
            $code = isset($row['districtcode']) ? (string) $row['districtcode'] : null;
            $nameEn = $row['districtnameenglish'] ?? $row['name'] ?? null;
            $nameMr = $row['districtlocalname'] ?? null;
            $stateCode = isset($row['statecode']) ? trim((string) $row['statecode']) : '';
            if ($code === null || $nameEn === null) {
                continue;
            }
            $stateId = null;
            if ($stateCode !== '' && isset($stateCodeToId[$stateCode])) {
                $stateId = (int) $stateCodeToId[$stateCode];
            } elseif ($singleStateId !== null) {
                $stateId = $singleStateId;
            } else {
                $this->command?->warn('GeoSeeder districts: "'.$nameEn.'" skipped (add `statecode` to districts.json when multiple states exist).');

                continue;
            }
            $district = District::firstOrCreate(
                ['state_id' => $stateId, 'name' => $nameEn],
            );
            if ($nameMr && ! $district->name_mr) {
                $district->name_mr = trim($nameMr);
                $district->save();
            }
            $map[$code] = $district->id;
        }

        $this->command->info('Districts: '.count($map).' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $districtCodeToId
     * @return array<string, int> subdistrictcode -> id
     */
    private function seedTalukas(array $districtCodeToId): array
    {
        $path = $this->geoPath.'/talukas.json';
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

        $this->command->info('Talukas: '.count($map).' loaded.');

        return $map;
    }

    /**
     * @param  array<string, int>  $subdistrictCodeToId
     */
    private function seedVillages(array $subdistrictCodeToId): void
    {
        $path = $this->geoPath.'/villages.json';
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

        $this->command->info('Villages: '.$count.' loaded, '.$skipped.' skipped.');
    }

    private function logGeoManifestIfPresent(): void
    {
        $path = $this->geoPath.'/geo_manifest.json';
        if (! is_readable($path)) {
            return;
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest)) {
            return;
        }
        $version = (string) ($manifest['version'] ?? '');
        $checksum = (string) ($manifest['files']['cities.json']['checksum'] ?? '');
        if ($version !== '' || $checksum !== '') {
            $this->command?->info('Geo manifest: version='.$version.' checksum='.$checksum);
        }
    }

    /**
     * Step 6: seed promoted city entries from SSOT JSON (append-only file).
     *
     * @param  array<string, int>  $stateCodeToId
     */
    private function seedPromotedCitiesFromJson(array $stateCodeToId): void
    {
        $path = $this->geoPath.'/cities.json';
        if (! is_readable($path)) {
            return;
        }
        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            return;
        }

        $normalizer = app(LocationNormalizationService::class);
        $count = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $stateCode = trim((string) ($row['statecode'] ?? ''));
            $districtName = trim((string) ($row['district'] ?? ''));
            $talukaName = trim((string) ($row['taluka'] ?? ''));
            $cityName = trim((string) ($row['name'] ?? ''));
            $aliases = is_array($row['aliases'] ?? null) ? $row['aliases'] : [];

            if ($stateCode === '' || $districtName === '' || $talukaName === '' || $cityName === '') {
                $skipped++;
                continue;
            }
            $stateId = $stateCodeToId[$stateCode] ?? null;
            if ($stateId === null) {
                $skipped++;
                continue;
            }

            $district = District::firstOrCreate(
                ['state_id' => (int) $stateId, 'name' => $districtName]
            );
            $taluka = Taluka::firstOrCreate(
                ['district_id' => (int) $district->id, 'name' => $talukaName]
            );
            $city = City::firstOrCreate(
                ['taluka_id' => (int) $taluka->id, 'name' => $cityName]
            );

            foreach ($aliases as $aliasValue) {
                if (! is_scalar($aliasValue)) {
                    continue;
                }
                $aliasName = trim((string) $aliasValue);
                if ($aliasName === '') {
                    continue;
                }
                $normalizedAlias = $normalizer->mergeKeyFromRaw($aliasName);
                if ($normalizedAlias === '') {
                    continue;
                }
                CityAlias::query()->firstOrCreate(
                    ['city_id' => (int) $city->id, 'normalized_alias' => $normalizedAlias],
                    ['alias_name' => $aliasName, 'is_active' => true]
                );
            }

            $count++;
        }

        $this->command?->info('Promoted cities from cities.json: '.$count.' loaded, '.$skipped.' skipped.');
    }
}
