<?php

namespace Database\Seeders;

use App\Models\Country;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Canonical world countries from a packaged snapshot (ISO 3166-1 alpha-2 `cca2` + English `name.common`).
 * Data source snapshot: restcountries.com (fields name, cca2), vendored as JSON in the repo.
 * Idempotent: upserts by {@code iso_alpha2} so existing FK ids stay stable when rows already exist.
 */
class CountriesMasterSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/countries_restcountries.json');
        if (! File::isReadable($path)) {
            $this->command?->error('Missing countries JSON: '.$path);

            return;
        }

        /** @var array<string, string|null> $mrByIso */
        $mrByIso = is_readable(database_path('seeders/data/country_name_mr_overrides.php'))
            ? require database_path('seeders/data/country_name_mr_overrides.php')
            : [];
        if (! is_array($mrByIso)) {
            $mrByIso = [];
        }

        $mrByEnglish = LocationMarathiLabels::englishToMarathi();

        $payload = json_decode(File::get($path), true);
        if (! is_array($payload)) {
            $this->command?->error('Invalid countries JSON.');

            return;
        }

        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['cca2']) ? strtoupper(trim((string) $row['cca2'])) : '';
            if ($code === '' || strlen($code) !== 2) {
                continue;
            }
            $nameEn = trim((string) ($row['name']['common'] ?? ''));
            if ($nameEn === '') {
                continue;
            }

            $nameMr = $mrByIso[$code] ?? ($mrByEnglish[$nameEn] ?? null);

            Country::updateOrCreate(
                ['iso_alpha2' => $code],
                [
                    'name' => $nameEn,
                    'name_mr' => $nameMr,
                ]
            );
        }
    }
}
