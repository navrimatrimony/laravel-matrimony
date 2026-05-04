<?php

namespace Tests\Feature\Location;

use App\Models\City;
use App\Models\LocationAlias;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyGeoIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_geo_integrity_passes_for_consistent_json(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        $tmp = $this->tmpJsonPath('geo-verify-pass');
        file_put_contents($tmp, json_encode([
            [
                'name' => 'Pune City',
                'statecode' => '27',
                'district' => 'Pune',
                'taluka' => 'Haveli',
                'aliases' => ['pune'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->artisan('location:verify-geo-integrity', ['--path' => $tmp, '--strict' => true])
            ->assertSuccessful();
    }

    public function test_verify_geo_integrity_fails_in_strict_mode_for_missing_hierarchy(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $tmp = $this->tmpJsonPath('geo-verify-fail');
        file_put_contents($tmp, json_encode([
            [
                'name' => 'Unknown City',
                'statecode' => '27',
                'district' => 'Pune',
                'taluka' => 'Haveli',
                'aliases' => ['unknown-city'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->artisan('location:verify-geo-integrity', ['--path' => $tmp, '--strict' => true])
            ->assertFailed();
    }

    private function tmpJsonPath(string $prefix): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$prefix.'-'.uniqid('', true).'.json';
    }
}
